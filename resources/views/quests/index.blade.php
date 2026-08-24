@extends('layouts.app')

@section('title', 'Daily quests')

@push('styles')
<style>
    .quests { max-width: 640px; }

    .quests__lede {
        font-size: 18px;
        line-height: 1.55;
        color: var(--ink-soft);
        margin: 0 0 30px;
        max-width: 62ch;
    }

    .quest {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 96px;
        align-items: center;
        gap: 14px;
        margin-bottom: 22px;
    }

    /* The bar is the progress readout and the label at once, the way the
       legacy page had it — the number inside the track means the row reads
       without a legend. */
    .track {
        position: relative;
        height: 44px;
        border-radius: 22px;
        background: var(--rule);
        overflow: hidden;
    }

    .track span {
        position: absolute;
        inset: 0 auto 0 0;
        background: var(--brand-fill);
        transition: width 200ms ease;
    }

    .track b {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        height: 100%;
        font-family: var(--mono);
        font-variant-numeric: tabular-nums;
        font-size: 14px;
        font-weight: 400;
        color: var(--ink-soft);
    }

    /* Once the fill is under the text, ink-soft on the gradient is unreadable.
       Only flips at 100% because that's the only width where the fill is
       guaranteed to be behind the centred label. */
    .quest--done .track b { color: var(--paper); }

    .quest__label {
        grid-column: 1;
        font-size: 15px;
        color: var(--ink-soft);
        margin: 6px 0 0;
    }

    .reward {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        height: 44px;
        border-radius: 5px;
        font-family: var(--mono);
        font-variant-numeric: tabular-nums;
        font-size: 15px;
        border: 1px dashed var(--rule);
        background: none;
        color: var(--ink-soft);
        cursor: not-allowed;
        padding: 0;
    }

    /* Claimable is the only interactive state. Locked and claimed are both
       inert, and look different from each other so "not yet" doesn't read as
       "already had it". */
    .reward--open {
        border: 0;
        background: var(--brand-fill);
        color: var(--paper);
        cursor: pointer;
        box-shadow: 2.5px 3px 6px var(--shadow);
        font: inherit;
        font-size: 15px;
    }

    .reward--open:hover,
    .reward--open:focus-visible { color: var(--brand-hover); }

    .reward--taken {
        border-style: solid;
        color: var(--brand-a);
    }

    .reward form { margin: 0; }

    @media (max-width: 520px) {
        .quest { grid-template-columns: minmax(0, 1fr) 76px; }
    }
</style>
@endpush

@section('content')
    @if (session('status'))
        <p class="flash" role="status">{{ session('status') }}</p>
    @endif

    <div class="page-head">
        <p class="eyebrow">Daily</p>
        <h1>Daily quests</h1>
    </div>

    <div class="quests">
        <p class="quests__lede">
            Points you earn today count toward every tier at once. Claim each
            one for credits — progress resets at midnight in your own timezone.
        </p>

        @foreach ($quests as $quest)
            <div class="quest {{ $quest['progress'] >= $quest['threshold'] ? 'quest--done' : '' }}">
                <div
                    class="track"
                    role="progressbar"
                    aria-valuenow="{{ $quest['progress'] }}"
                    aria-valuemin="0"
                    aria-valuemax="{{ $quest['threshold'] }}"
                    aria-label="{{ $quest['label'] }}"
                >
                    <span style="width: {{ ($quest['progress'] / $quest['threshold']) * 100 }}%"></span>
                    <b>{{ number_format($quest['progress']) }} / {{ number_format($quest['threshold']) }}</b>
                </div>

                @if ($quest['claimable'])
                    <form method="post" action="{{ route('quests.claim') }}">
                        @csrf
                        <input type="hidden" name="quest" value="{{ $quest['key'] }}">
                        <button class="reward reward--open" type="submit">
                            {{ $quest['reward'] }} C
                        </button>
                    </form>
                @else
                    <span class="reward {{ $quest['claimed'] ? 'reward--taken' : '' }}">
                        {{ $quest['claimed'] ? 'Claimed' : $quest['reward'].' C' }}
                    </span>
                @endif

                <p class="quest__label">{{ $quest['label'] }}</p>
            </div>
        @endforeach
    </div>
@endsection
