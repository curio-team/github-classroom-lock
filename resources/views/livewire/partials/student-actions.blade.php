{{-- Move one student to another group, or remove them. Uses the #group-names datalist. --}}
@php
    $hasAccess = in_array($student->status, [\App\Enums\StudentStatus::Joined, \App\Enums\StudentStatus::Invited], true);
    $removeQuestion = $hasAccess
        ? "{$student->github_login} verwijderen? De toegang op GitHub wordt meteen ingetrokken: de student gaat uit het team en, als die voor dit examen is uitgenodigd, uit {$cohort->organization}."
        : "{$student->github_login} van de lijst verwijderen?";
    $moveNote = $student->status === \App\Enums\StudentStatus::Joined
        ? 'Het team op GitHub wordt meteen aangepast.'
        : null;
@endphp
<div x-data="{
    open: false,
    busy: false,
    group: @js($student->group?->name ?? ''),
    run(call) {
        if (this.busy) return;
        this.busy = true;
        call().finally(() => this.busy = false);
    },
}" class="flex flex-col items-end gap-2">
    <button type="button" class="text-sm font-bold text-primary hover:text-primary-dark cursor-pointer" x-on:click="open = ! open" x-text="open ? 'Sluiten' : 'Wijzigen'">Wijzigen</button>

    <div x-show="open" x-cloak class="flex flex-col items-end gap-2 w-full" x-bind:class="busy && 'opacity-50 pointer-events-none'">
        <div class="flex flex-wrap justify-end items-center gap-2">
            <input type="text" x-model="group" list="group-names" class="input text-sm min-w-0 w-40" aria-label="Nieuwe groep voor {{ $student->github_login }}" autocomplete="off"
                x-on:keydown.enter.prevent="run(() => $wire.moveStudent({{ $student->id }}, group))">
            <x-buttons.secondary tight x-on:click.prevent="run(() => $wire.moveStudent({{ $student->id }}, group))">Verplaatsen</x-buttons.secondary>
            <x-buttons.danger tight x-on:click.prevent="if (confirm(@js($removeQuestion))) run(() => $wire.removeStudent({{ $student->id }}))">Verwijderen</x-buttons.danger>
        </div>
        <span class="text-xs text-muted" x-show="busy">Bezig…</span>
        @if ($moveNote)
            <span class="text-xs text-muted" x-show="! busy">{{ $moveNote }}</span>
        @endif
    </div>
</div>
