<?php

namespace App\Modules\Content\Domain\Render;

enum RenderTarget: string
{
    case EmailHtml = 'email_html';
    case WebHtml = 'web_html';

    public function mediaType(): string
    {
        return 'text/html; charset=utf-8';
    }
}
