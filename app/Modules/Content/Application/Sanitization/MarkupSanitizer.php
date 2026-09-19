<?php

namespace App\Modules\Content\Application\Sanitization;

use App\Modules\Content\Domain\Authoring\AuthoringTarget;
use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use InvalidArgumentException;
use RuntimeException;

final class MarkupSanitizer
{
    private const int MAX_BYTES = 262144;

    private const int MAX_NODES = 5000;

    /** @var list<string> */
    private const array COMMON_TAGS = [
        'a',
        'br',
        'div',
        'em',
        'h1',
        'h2',
        'h3',
        'h4',
        'h5',
        'h6',
        'img',
        'li',
        'ol',
        'p',
        'span',
        'strong',
        'table',
        'tbody',
        'td',
        'th',
        'thead',
        'tr',
        'ul',
    ];

    /** @var list<string> */
    private const array WEB_EXTRA_TAGS = [
        'article',
        'blockquote',
        'footer',
        'header',
        'main',
        'nav',
        'section',
    ];

    /** @var list<string> */
    private const array GLOBAL_ATTRIBUTES = [
        'aria-hidden',
        'aria-label',
        'class',
        'dir',
        'lang',
        'role',
        'style',
        'title',
    ];

    /** @var array<string, list<string>> */
    private const array TAG_ATTRIBUTES = [
        'a' => ['href', 'rel', 'target'],
        'img' => ['alt', 'data-vsn-asset-ref', 'height', 'width'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan'],
    ];

    /** @var list<string> */
    private const array ACTIVE_ELEMENTS = [
        'applet',
        'audio',
        'base',
        'embed',
        'form',
        'iframe',
        'input',
        'link',
        'math',
        'meta',
        'object',
        'script',
        'source',
        'style',
        'svg',
        'template',
        'video',
    ];

    /** @var array<string, string> */
    private const array STYLE_KINDS = [
        'background-color' => 'color',
        'color' => 'color',
        'font-family' => 'font_family',
        'font-size' => 'length',
        'font-style' => 'font_style',
        'font-weight' => 'font_weight',
        'height' => 'length_auto',
        'line-height' => 'line_height',
        'margin' => 'spacing',
        'margin-bottom' => 'length_auto',
        'margin-left' => 'length_auto',
        'margin-right' => 'length_auto',
        'margin-top' => 'length_auto',
        'max-width' => 'length_auto',
        'min-width' => 'length_auto',
        'padding' => 'spacing',
        'padding-bottom' => 'length',
        'padding-left' => 'length',
        'padding-right' => 'length',
        'padding-top' => 'length',
        'text-align' => 'text_align',
        'text-decoration' => 'text_decoration',
        'width' => 'length_auto',
    ];

    public function sanitize(string $markup, AuthoringTarget $target): SanitizedMarkup
    {
        if (trim($markup) === '') {
            throw new InvalidArgumentException('Authored markup must not be empty.');
        }

        if (strlen($markup) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Authored markup exceeds the sanitizer byte limit.');
        }

        if (preg_match('//u', $markup) !== 1) {
            throw new InvalidArgumentException('Authored markup must be valid UTF-8.');
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $markup) === 1) {
            throw new InvalidArgumentException('Authored markup contains forbidden control characters.');
        }

        if (preg_match('/<!(?:DOCTYPE|ENTITY)|<\?xml/i', $markup) === 1) {
            throw new InvalidArgumentException('Authored markup cannot declare document types, entities, or XML instructions.');
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML(
                '<div data-vsn-sanitizer-root="1">'.$markup.'</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded === false || $document->documentElement === null) {
            throw new InvalidArgumentException('Authored markup could not be parsed safely.');
        }

        $root = $document->documentElement;
        $nodeCount = 0;
        $sanitized = '';

        foreach ($root->childNodes as $child) {
            $sanitized .= $this->renderNode($child, $target, $nodeCount);
        }

        if (trim($sanitized) === '') {
            throw new InvalidArgumentException('Authored markup does not contain supported content.');
        }

        return new SanitizedMarkup(
            target: $target,
            markup: $sanitized,
        );
    }

    private function renderNode(DOMNode $node, AuthoringTarget $target, int &$nodeCount): string
    {
        $nodeCount++;

        if ($nodeCount > self::MAX_NODES) {
            throw new InvalidArgumentException('Authored markup exceeds the sanitizer node limit.');
        }

        if ($node instanceof DOMText) {
            return htmlspecialchars(
                str_replace(["\r\n", "\r"], "\n", $node->wholeText),
                ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                'UTF-8',
            );
        }

        if ($node instanceof DOMComment) {
            return '';
        }

        if ($node instanceof DOMElement === false) {
            throw new InvalidArgumentException('Unsupported authored markup node type.');
        }

        $tag = strtolower($node->tagName);

        if (in_array($tag, self::ACTIVE_ELEMENTS, true)) {
            throw new InvalidArgumentException("Executable or active authored markup element is forbidden: {$tag}");
        }

        if (in_array($tag, $this->allowedTags($target), true) === false) {
            throw new InvalidArgumentException("Unsupported authored markup element for {$target->value}: {$tag}");
        }

        $attributes = $this->sanitizeAttributes($node, $tag);

        if ($tag === 'a' && array_key_exists('href', $attributes) === false) {
            throw new InvalidArgumentException('Authored link elements require href.');
        }

        if ($tag === 'img') {
            if (array_key_exists('data-vsn-asset-ref', $attributes) === false) {
                throw new InvalidArgumentException('Authored image elements require data-vsn-asset-ref; remote src is not accepted.');
            }

            if (array_key_exists('alt', $attributes) === false) {
                throw new InvalidArgumentException('Authored image elements require alt text or explicit decorative semantics.');
            }

            $decorative = ($attributes['role'] ?? null) === 'presentation'
                || ($attributes['aria-hidden'] ?? null) === 'true';

            if ($attributes['alt'] === '' && $decorative === false) {
                throw new InvalidArgumentException('Empty image alt text requires decorative semantics.');
            }
        }

        if (($attributes['target'] ?? null) === '_blank') {
            $rel = array_filter(explode(' ', $attributes['rel'] ?? ''));
            $rel[] = 'noopener';
            $rel[] = 'noreferrer';
            $rel = array_values(array_unique($rel));
            sort($rel);
            $attributes['rel'] = implode(' ', $rel);
        }

        ksort($attributes);

        $renderedAttributes = '';

        foreach ($attributes as $name => $value) {
            $renderedAttributes .= ' '.$name.'="'.htmlspecialchars(
                $value,
                ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                'UTF-8',
            ).'"';
        }

        if (in_array($tag, ['br', 'img'], true)) {
            return "<{$tag}{$renderedAttributes}>";
        }

        $children = '';

        foreach ($node->childNodes as $child) {
            $children .= $this->renderNode($child, $target, $nodeCount);
        }

        return "<{$tag}{$renderedAttributes}>{$children}</{$tag}>";
    }

    /**
     * @return array<string, string>
     */
    private function sanitizeAttributes(DOMElement $element, string $tag): array
    {
        $allowed = array_merge(
            self::GLOBAL_ATTRIBUTES,
            self::TAG_ATTRIBUTES[$tag] ?? [],
        );

        $sanitized = [];

        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->name);
            $value = $attribute->value;

            if (str_starts_with($name, 'on')) {
                throw new InvalidArgumentException("Authored event handler attribute is forbidden: {$name}");
            }

            if (in_array($name, $allowed, true) === false) {
                throw new InvalidArgumentException("Unsupported authored markup attribute on {$tag}: {$name}");
            }

            $sanitized[$name] = $this->sanitizeAttributeValue($name, $value);
        }

        return $sanitized;
    }

    private function sanitizeAttributeValue(string $name, string $value): string
    {
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));

        if (strlen($value) > 2048) {
            throw new InvalidArgumentException("Authored markup attribute is too long: {$name}");
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException("Authored markup attribute contains control characters: {$name}");
        }

        return match ($name) {
            'href' => $this->sanitizeHref($value),
            'style' => $this->sanitizeStyle($value),
            'target' => $this->sanitizeTarget($value),
            'rel' => $this->sanitizeRel($value),
            'class' => $this->sanitizeClass($value),
            'role' => $this->sanitizeRole($value),
            'aria-hidden' => $this->sanitizeAriaHidden($value),
            'dir' => $this->sanitizeDirection($value),
            'lang' => $this->sanitizeLanguage($value),
            'width', 'height' => $this->sanitizeDimension($name, $value),
            'colspan', 'rowspan' => $this->sanitizeSpan($name, $value),
            'data-vsn-asset-ref' => $this->sanitizeAssetReference($value),
            default => $value,
        };
    }

    private function sanitizeHref(string $href): string
    {
        if ($href === '') {
            throw new InvalidArgumentException('Authored href must not be empty.');
        }

        $compact = strtolower((string) preg_replace('/[\x00-\x20\x7F]+/', '', $href));

        if (str_starts_with($compact, '//')) {
            throw new InvalidArgumentException('Protocol-relative authored URLs are forbidden.');
        }

        foreach (['https://', 'http://', 'mailto:', 'tel:', '#', '/'] as $allowedPrefix) {
            if (str_starts_with($compact, $allowedPrefix)) {
                return $href;
            }
        }

        throw new InvalidArgumentException('Authored href uses an unsupported or unsafe URL scheme.');
    }

    private function sanitizeStyle(string $style): string
    {
        if ($style === '') {
            throw new InvalidArgumentException('Authored style attribute must not be empty.');
        }

        $lower = strtolower($style);

        foreach (['\\', '<', '>', '@', 'url(', 'expression(', 'javascript:', 'data:', 'behavior', 'binding'] as $forbidden) {
            if (str_contains($lower, $forbidden)) {
                throw new InvalidArgumentException('Authored style contains a forbidden CSS construct.');
            }
        }

        $declarations = [];

        foreach (explode(';', $style) as $declaration) {
            $declaration = trim($declaration);

            if ($declaration === '') {
                continue;
            }

            if (str_contains($declaration, ':') === false) {
                throw new InvalidArgumentException('Authored style contains an invalid declaration.');
            }

            [$property, $value] = array_map('trim', explode(':', $declaration, 2));
            $property = strtolower($property);

            if (array_key_exists($property, self::STYLE_KINDS) === false) {
                throw new InvalidArgumentException("Unsupported authored CSS property: {$property}");
            }

            if (array_key_exists($property, $declarations)) {
                throw new InvalidArgumentException("Duplicate authored CSS property is forbidden: {$property}");
            }

            $declarations[$property] = $this->sanitizeStyleValue(
                self::STYLE_KINDS[$property],
                $value,
                $property,
            );
        }

        if ($declarations === []) {
            throw new InvalidArgumentException('Authored style does not contain supported declarations.');
        }

        ksort($declarations);

        return implode(';', array_map(
            static fn (string $property, string $value): string => "{$property}:{$value}",
            array_keys($declarations),
            array_values($declarations),
        ));
    }

    private function sanitizeStyleValue(string $kind, string $value, string $property): string
    {
        $value = trim($value);

        $valid = match ($kind) {
            'color' => preg_match('/^(?:#[0-9a-f]{3,8}|rgba?\([0-9.% ,]+\)|[a-z]{1,20})$/i', $value) === 1,
            'font_family' => preg_match('/^[a-z0-9 ,\'"_-]{1,128}$/i', $value) === 1,
            'font_style' => in_array(strtolower($value), ['normal', 'italic'], true),
            'font_weight' => preg_match('/^(?:normal|bold|[1-9]00)$/i', $value) === 1,
            'length' => $this->isLength($value, false),
            'length_auto' => $this->isLength($value, true),
            'line_height' => preg_match('/^(?:\d+(?:\.\d+)?|\d+(?:\.\d+)?(?:px|em|rem|%))$/i', $value) === 1,
            'spacing' => $this->isSpacing($value),
            'text_align' => in_array(strtolower($value), ['center', 'justify', 'left', 'right'], true),
            'text_decoration' => in_array(strtolower($value), ['line-through', 'none', 'underline'], true),
            default => false,
        };

        if ($valid === false) {
            throw new InvalidArgumentException("Unsafe or invalid authored CSS value for {$property}.");
        }

        return strtolower($value);
    }

    private function isLength(string $value, bool $allowAuto): bool
    {
        if ($allowAuto && strtolower($value) === 'auto') {
            return true;
        }

        return preg_match('/^(?:0|\d+(?:\.\d+)?(?:px|em|rem|%))$/i', $value) === 1;
    }

    private function isSpacing(string $value): bool
    {
        $parts = preg_split('/\s+/', trim($value));

        if ($parts === false || $parts === [] || count($parts) > 4) {
            return false;
        }

        foreach ($parts as $part) {
            if ($this->isLength($part, true) === false) {
                return false;
            }
        }

        return true;
    }

    private function sanitizeTarget(string $target): string
    {
        $target = strtolower($target);

        if (in_array($target, ['_blank', '_self'], true) === false) {
            throw new InvalidArgumentException('Authored link target is unsupported.');
        }

        return $target;
    }

    private function sanitizeRel(string $rel): string
    {
        $tokens = preg_split('/\s+/', strtolower(trim($rel)));

        if ($tokens === false) {
            throw new RuntimeException('Unable to parse authored rel tokens.');
        }

        $tokens = array_values(array_filter($tokens));

        foreach ($tokens as $token) {
            if (in_array($token, ['nofollow', 'noopener', 'noreferrer', 'sponsored', 'ugc'], true) === false) {
                throw new InvalidArgumentException("Unsupported authored rel token: {$token}");
            }
        }

        $tokens = array_values(array_unique($tokens));
        sort($tokens);

        return implode(' ', $tokens);
    }

    private function sanitizeClass(string $class): string
    {
        if (preg_match('/^[a-z0-9 _-]{1,256}$/i', $class) !== 1) {
            throw new InvalidArgumentException('Authored class attribute contains unsupported characters.');
        }

        return preg_replace('/\s+/', ' ', $class) ?? $class;
    }

    private function sanitizeRole(string $role): string
    {
        $role = strtolower($role);

        if (in_array($role, ['article', 'button', 'heading', 'img', 'none', 'presentation', 'region'], true) === false) {
            throw new InvalidArgumentException('Authored accessibility role is unsupported.');
        }

        return $role;
    }

    private function sanitizeAriaHidden(string $value): string
    {
        $value = strtolower($value);

        if (in_array($value, ['false', 'true'], true) === false) {
            throw new InvalidArgumentException('Authored aria-hidden must be true or false.');
        }

        return $value;
    }

    private function sanitizeDirection(string $value): string
    {
        $value = strtolower($value);

        if (in_array($value, ['auto', 'ltr', 'rtl'], true) === false) {
            throw new InvalidArgumentException('Authored direction is unsupported.');
        }

        return $value;
    }

    private function sanitizeLanguage(string $value): string
    {
        if (preg_match('/^[a-z]{2,8}(?:-[a-z0-9]{1,8})*$/i', $value) !== 1) {
            throw new InvalidArgumentException('Authored language tag is invalid.');
        }

        return strtolower($value);
    }

    private function sanitizeDimension(string $name, string $value): string
    {
        if (ctype_digit($value) === false) {
            throw new InvalidArgumentException("Authored {$name} must be an integer.");
        }

        $dimension = (int) $value;

        if ($dimension < 1 || $dimension > 4096) {
            throw new InvalidArgumentException("Authored {$name} is outside the supported bounds.");
        }

        return (string) $dimension;
    }

    private function sanitizeSpan(string $name, string $value): string
    {
        if (ctype_digit($value) === false) {
            throw new InvalidArgumentException("Authored {$name} must be an integer.");
        }

        $span = (int) $value;

        if ($span < 1 || $span > 12) {
            throw new InvalidArgumentException("Authored {$name} is outside the supported bounds.");
        }

        return (string) $span;
    }

    private function sanitizeAssetReference(string $value): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9._:-]{0,190}$/i', $value) !== 1) {
            throw new InvalidArgumentException('Authored asset reference is invalid.');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function allowedTags(AuthoringTarget $target): array
    {
        return $target === AuthoringTarget::Web
            ? array_merge(self::COMMON_TAGS, self::WEB_EXTRA_TAGS)
            : self::COMMON_TAGS;
    }
}
