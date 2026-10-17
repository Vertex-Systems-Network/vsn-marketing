export function FormField({
    id,
    label,
    type = 'text',
    autoComplete,
    defaultValue,
    error,
}: {
    id: string;
    label: string;
    type?: string;
    autoComplete?: string;
    defaultValue?: string;
    error?: string;
}) {
    const errorId = `${id}-error`;

    return (
        <div className="flex flex-col gap-1.5">
            <label htmlFor={id} className="text-[12px] font-semibold text-ink-2">
                {label}
            </label>
            <input
                id={id}
                name={id}
                type={type}
                autoComplete={autoComplete}
                defaultValue={defaultValue}
                required
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? errorId : undefined}
                className="h-12 rounded-[12px] border border-line-strong bg-field px-3.5 text-[14px] text-ink aria-invalid:border-error"
            />
            {error && (
                <p id={errorId} className="text-[12px] font-semibold text-error">
                    {error}
                </p>
            )}
        </div>
    );
}
