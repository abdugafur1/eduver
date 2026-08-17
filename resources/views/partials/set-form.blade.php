{{--
    Shared by sets.create and sets.edit. Expects:
      $action    form target
      $method    'post' or 'patch'
      $set       StudySet|null — null when creating
      $submit    button label
--}}

@push('styles')
<style>
    .form-shell {
        max-width: 620px;
        background: var(--tile);
        border-radius: 2.5px;
        box-shadow: 2.5px 2.5px 5px 2px var(--shadow);
        padding: 26px 26px 24px;
    }

    .form-row { margin-bottom: 22px; }
    .form-row:last-of-type { margin-bottom: 0; }

    .form-row > label {
        display: block;
        font-family: var(--mono);
        font-size: 11px;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--ink-soft);
        margin-bottom: 7px;
    }

    .form-row input[type="text"],
    .form-row textarea {
        display: block;
        width: 100%;
        padding: 10px 12px;
        border: 1px solid var(--rule);
        border-radius: 2.5px;
        background: var(--paper);
        font-family: var(--body);
        font-weight: 300;
        font-size: 17px;
        line-height: 1.5;
        color: var(--ink);
    }

    .form-row textarea { min-height: 96px; resize: vertical; }

    .form-row input[type="text"]:focus,
    .form-row textarea:focus { outline: 0; border-color: var(--brand-a); }

    .form-hint {
        margin: 6px 0 0;
        font-size: 14px;
        line-height: 1.45;
        color: var(--ink-soft);
    }

    /* Gold, not red: the palette has no red, and gold is already the flag
       colour for private visibility and high-miss cards. */
    .form-error {
        margin: 6px 0 0;
        font-family: var(--mono);
        font-size: 12px;
        color: var(--gold);
    }

    .form-row input[type="text"][aria-invalid="true"],
    .form-row textarea[aria-invalid="true"] { border-color: var(--gold); }

    /* ---------- visibility ---------- */

    .choices { display: grid; gap: 8px; }

    .choice {
        display: grid;
        grid-template-columns: 16px minmax(0, 1fr);
        gap: 11px;
        align-items: start;
        padding: 12px 14px;
        border: 1px solid var(--rule);
        border-radius: 2.5px;
        background: var(--paper);
        cursor: pointer;
    }

    .choice:hover { border-color: var(--brand-a); }
    .choice:has(input:checked) { border-color: var(--brand-a); background: var(--tile); }
    .choice:has(input:focus-visible) { outline: 2px solid var(--brand-a); outline-offset: 2px; }

    .choice input { accent-color: var(--brand-a); width: 15px; height: 15px; margin: 4px 0 0; }

    .choice b {
        display: block;
        font-weight: 500;
        font-size: 16px;
        line-height: 1.3;
    }

    .choice span {
        display: block;
        margin-top: 2px;
        font-size: 14px;
        line-height: 1.45;
        color: var(--ink-soft);
    }

    .form-actions {
        display: flex;
        align-items: center;
        gap: 14px;
        margin-top: 26px;
        padding-top: 20px;
        border-top: 1px solid var(--rule);
    }
</style>
@endpush

<form class="form-shell" method="post" action="{{ $action }}">
    @csrf
    @if ($method !== 'post')
        @method($method)
    @endif

    <div class="form-row">
        <label for="title">Title</label>
        <input
            type="text"
            id="title"
            name="title"
            maxlength="128"
            required
            autofocus
            value="{{ old('title', $set?->title) }}"
            @error('title') aria-invalid="true" aria-describedby="title-error" @enderror
        >
        @error('title')
            <p class="form-error" id="title-error">{{ $message }}</p>
        @enderror
    </div>

    <div class="form-row">
        <label for="description">Description</label>
        <textarea
            id="description"
            name="description"
            maxlength="1000"
            @error('description') aria-invalid="true" aria-describedby="description-error" @enderror
        >{{ old('description', $set?->description) }}</textarea>
        <p class="form-hint">Optional. Shown on the set tile when people browse.</p>
        @error('description')
            <p class="form-error" id="description-error">{{ $message }}</p>
        @enderror
    </div>

    <div class="form-row">
        <label id="visibility-label">Who can see it</label>

        @php
            $current = old('visibility', $set?->visibility ?? \App\Models\StudySet::VISIBILITY_PUBLIC);

            $options = [
                \App\Models\StudySet::VISIBILITY_PUBLIC => [
                    'Anyone',
                    'Appears in the library and can be studied by anyone, signed in or not.',
                ],
                \App\Models\StudySet::VISIBILITY_UNLISTED => [
                    'Anyone with the link',
                    'Stays out of the library. People you send the link to can open it.',
                ],
                \App\Models\StudySet::VISIBILITY_PRIVATE => [
                    'Only you',
                    'Nobody else can open it, even with the link.',
                ],
            ];
        @endphp

        <div class="choices" role="radiogroup" aria-labelledby="visibility-label">
            @foreach ($options as $value => [$heading, $blurb])
                <label class="choice">
                    <input type="radio" name="visibility" value="{{ $value }}" @checked($current === $value)>
                    <span>
                        <b>{{ $heading }}</b>
                        <span>{{ $blurb }}</span>
                    </span>
                </label>
            @endforeach
        </div>

        @error('visibility')
            <p class="form-error">{{ $message }}</p>
        @enderror
    </div>

    <div class="form-actions">
        <button class="btn" type="submit">{{ $submit }}</button>
        <a class="btn btn--quiet" href="{{ $set ? route('sets.terms', $set) : route('sets.index') }}">Cancel</a>
    </div>
</form>
