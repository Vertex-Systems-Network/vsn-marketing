type Field = { id: string; type: string; operators: string[] };
type Rule = Record<string, unknown>;

type Props = {
    root: Rule;
    fields: Field[];
    events: string[];
    onChange: (root: Rule) => void;
};

const firstRule = (fields: Field[]): Rule => ({
    type: 'attribute', field: fields[0]?.id ?? 'contact.created_at',
    operator: fields[0]?.operators[0] ?? 'is_set',
    ...(fields[0]?.operators[0] === 'is_set' ? {} : { value: fields[0]?.type === 'timestamp' ? new Date().toISOString() : '' }),
});

function editChild(root: Rule, index: number, value: Rule | null): Rule {
    const children = Array.isArray(root.children) ? [...root.children] as Rule[] : [];
    if (value === null) children.splice(index, 1);
    else children[index] = value;
    return { ...root, children };
}

function Node({ node, fields, events, onChange, onRemove, depth }: {
    node: Rule; fields: Field[]; events: string[]; onChange: (value: Rule) => void;
    onRemove?: () => void; depth: number;
}) {
    const type = node.type;
    if (type === 'group') {
        const children = Array.isArray(node.children) ? node.children as Rule[] : [];
        return <fieldset className="rounded-xl border border-white/20 p-3">
            <legend className="px-1 text-sm text-neutral-200">{depth === 0 ? 'Audience rules' : 'Nested rule group'}</legend>
            <label className="block text-xs text-neutral-300">Match
                <select aria-label={`Group ${depth} matching`} value={String(node.operator)} onChange={(e) => onChange({ ...node, operator: e.target.value })} className="ml-2 rounded bg-neutral-800 p-2">
                    <option value="all">all rules (AND)</option><option value="any">any rule (OR)</option>
                </select>
            </label>
            <div className="mt-3 space-y-3">
                {children.map((child, index) => <Node key={index} node={child} fields={fields} events={events} depth={depth + 1}
                    onChange={(next) => onChange(editChild(node, index, next))}
                    onRemove={children.length > 1 ? () => onChange(editChild(node, index, null)) : undefined} />)}
            </div>
            <div className="mt-3 flex flex-wrap gap-2">
                <button type="button" onClick={() => onChange({ ...node, children: [...children, firstRule(fields)] })} className="rounded border border-white/20 px-3 py-2 text-xs">Add condition</button>
                <button type="button" onClick={() => onChange({ ...node, children: [...children, { type: 'not', child: firstRule(fields) }] })} className="rounded border border-amber-400/20 px-3 py-2 text-xs">Add exclusion (NOT)</button>
                {depth < 6 && <button type="button" onClick={() => onChange({ ...node, children: [...children, { type: 'group', operator: 'all', children: [firstRule(fields)] }] })} className="rounded border border-white/20 px-3 py-2 text-xs">Add nested group</button>}
                {onRemove && <button type="button" onClick={onRemove} className="rounded border border-rose-400/30 px-3 py-2 text-xs text-rose-100">Remove group</button>}
            </div>
        </fieldset>;
    }
    if (type === 'not') return <div className="rounded-xl border border-amber-400/20 p-3">
        <p className="mb-2 text-sm">Exclude contacts matching:</p>
        <Node node={node.child as Rule} fields={fields} events={events} depth={depth + 1}
            onChange={(child) => onChange({ ...node, child })} />
        {onRemove && <button type="button" onClick={onRemove} className="mt-2 text-xs text-rose-100">Remove exclusion</button>}
    </div>;

    const field = fields.find((item) => item.id === node.field) ?? fields[0];
    const isEvent = type === 'event';
    const noValue = node.operator === 'is_set' || node.operator === 'is_not_set';
    return <div className="flex flex-wrap items-end gap-2 rounded-xl border border-white/10 p-3">
        <label className="text-xs text-neutral-300">Condition
            <select value={isEvent ? 'event' : 'attribute'} onChange={(e) => onChange(e.target.value === 'event'
                ? { type: 'event', name: events[0], mode: 'exists', window: { kind: 'relative', days: 30 } }
                : firstRule(fields))} className="mt-1 block rounded bg-neutral-800 p-2">
                <option value="attribute">Attribute</option><option value="event" disabled={events.length === 0}>Event</option>
            </select>
        </label>
        {isEvent ? <>
            <label className="text-xs">Event<select aria-label="Event name" value={String(node.name)} onChange={(e) => onChange({ ...node, name: e.target.value })} className="mt-1 block rounded bg-neutral-800 p-2">
                {events.map((event) => <option key={event} value={event}>{event}</option>)}
            </select></label>
            <label className="text-xs">Occurrence<select aria-label="Event occurrence" value={String(node.mode)} onChange={(e) => onChange({ ...node, mode: e.target.value })} className="mt-1 block rounded bg-neutral-800 p-2">
                <option value="exists">Occurred</option><option value="not_exists">Did not occur</option>
            </select></label>
            <label className="text-xs">Within last days<input aria-label="Event window days" type="number" min="1" max="365" value={Number((node.window as Rule)?.days ?? 30)}
                onChange={(e) => onChange({ ...node, window: { kind: 'relative', days: Number(e.target.value) } })} className="mt-1 block w-24 rounded bg-neutral-800 p-2" /></label>
        </> : <>
            <label className="text-xs">Field<select aria-label="Rule field" value={String(node.field)} onChange={(e) => {
                const next = fields.find((item) => item.id === e.target.value);
                onChange({ type: 'attribute', field: e.target.value, operator: next?.operators[0] ?? 'is_set',
                    ...(next?.operators[0] === 'is_set' ? {} : { value: next?.type === 'timestamp' ? new Date().toISOString() : '' }) });
            }} className="mt-1 block rounded bg-neutral-800 p-2">{fields.map((item) => <option key={item.id} value={item.id}>{item.id}</option>)}</select></label>
            <label className="text-xs">Operator<select aria-label="Rule operator" value={String(node.operator)} onChange={(e) => onChange({
                type: 'attribute', field: node.field, operator: e.target.value,
                ...(['is_set', 'is_not_set'].includes(e.target.value) ? {} : { value: node.value ?? (field?.type === 'timestamp' ? new Date().toISOString() : '') }),
            })} className="mt-1 block rounded bg-neutral-800 p-2">{field?.operators.map((operator) => <option key={operator} value={operator}>{operator.replaceAll('_', ' ')}</option>)}</select></label>
            {!noValue && <label className="text-xs">{field?.type === 'timestamp' ? 'UTC timestamp (ISO 8601)' : 'Value'}<input aria-label="Rule value" type="text"
                value={String(node.value ?? '')} onChange={(e) => onChange({ ...node, value: e.target.value })}
                className="mt-1 block rounded bg-neutral-800 p-2" /></label>}
        </>}
        {onRemove && <button type="button" onClick={onRemove} className="rounded border border-rose-400/30 p-2 text-xs text-rose-100">Remove condition</button>}
    </div>;
}

export default function RuleBuilder({ root, fields, events, onChange }: Props) {
    if (fields.length === 0) return <p role="status">No targetable fields are available to this role.</p>;
    return <Node node={root} fields={fields} events={events} onChange={onChange} depth={0} />;
}
