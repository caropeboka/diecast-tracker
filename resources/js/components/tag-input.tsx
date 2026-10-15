import { X } from 'lucide-react';
import { useId, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

/**
 * Chips input: type a tag and press Enter or comma. Suggestions come from tags already used.
 */
export default function TagInput({
    id,
    value,
    onChange,
    suggestions,
    placeholder = 'JDM, Favorit…',
}: {
    id?: string;
    value: string[];
    onChange: (tags: string[]) => void;
    suggestions: string[];
    placeholder?: string;
}) {
    const [draft, setDraft] = useState('');
    const listId = useId();

    const has = (tag: string) =>
        value.some((t) => t.toLowerCase() === tag.toLowerCase());

    function add(raw: string) {
        const tags = raw
            .split(',')
            .map((t) => t.trim())
            .filter((t) => t && !has(t));

        if (tags.length) {
            onChange([...value, ...tags]);
        }

        setDraft('');
    }

    const unused = suggestions.filter((s) => !has(s));

    return (
        <div className="space-y-2">
            <div
                className={cn(
                    'flex min-h-9 flex-wrap items-center gap-1.5 rounded-md border border-input bg-transparent px-2 py-1.5 shadow-xs focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50',
                )}
            >
                {value.map((tag) => (
                    <Badge key={tag} variant="secondary" className="gap-1 pr-1">
                        {tag}
                        <button
                            type="button"
                            onClick={() =>
                                onChange(value.filter((t) => t !== tag))
                            }
                            className="rounded-sm hover:bg-foreground/10"
                            aria-label={`Hapus tag ${tag}`}
                        >
                            <X className="size-3" />
                        </button>
                    </Badge>
                ))}
                <input
                    id={id}
                    list={listId}
                    value={draft}
                    onChange={(e) => {
                        // Picking from the datalist fills the whole value at once.
                        if (suggestions.includes(e.target.value)) {
                            add(e.target.value);
                        } else {
                            setDraft(e.target.value);
                        }
                    }}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' || e.key === ',') {
                            e.preventDefault();
                            add(draft);
                        } else if (
                            e.key === 'Backspace' &&
                            draft === '' &&
                            value.length
                        ) {
                            onChange(value.slice(0, -1));
                        }
                    }}
                    onBlur={() => draft && add(draft)}
                    placeholder={value.length ? '' : placeholder}
                    className="min-w-24 flex-1 bg-transparent text-base outline-none placeholder:text-muted-foreground md:text-sm"
                />
                <datalist id={listId}>
                    {unused.map((tag) => (
                        <option key={tag} value={tag} />
                    ))}
                </datalist>
            </div>
            {unused.length > 0 && (
                <div className="flex flex-wrap gap-1">
                    {unused.slice(0, 10).map((tag) => (
                        <button
                            key={tag}
                            type="button"
                            onClick={() => add(tag)}
                            className="rounded-full border border-dashed px-2 py-0.5 text-xs text-muted-foreground hover:border-solid hover:text-foreground"
                        >
                            + {tag}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
