{{-- Adds one student without importing a list. Uses the #group-names datalist. --}}
<form wire:submit="addStudent" class="flex flex-col gap-2">
    <div class="flex flex-col sm:flex-row sm:items-end gap-2">
        <div class="flex flex-col gap-1 grow">
            <label for="newStudentLogin-{{ $context }}" class="label">GitHub-gebruikersnaam</label>
            <input id="newStudentLogin-{{ $context }}" type="text" wire:model="newStudentLogin" class="input" placeholder="octocat" autocomplete="off" spellcheck="false">
        </div>
        <div class="flex flex-col gap-1 grow">
            <label for="newStudentGroup-{{ $context }}" class="label">Groep</label>
            <input id="newStudentGroup-{{ $context }}" type="text" wire:model="newStudentGroup" list="group-names" class="input" placeholder="Kies of typ een nieuwe groep" autocomplete="off">
        </div>
        <button type="submit" class="button shrink-0" wire:loading.attr="disabled" wire:target="addStudent">
            <span wire:loading.remove wire:target="addStudent">Toevoegen</span>
            <span wire:loading wire:target="addStudent">Opzoeken op GitHub…</span>
        </button>
    </div>
    @error('newStudentLogin') <p class="text-sm font-medium text-danger-ink">{{ $message }}</p> @enderror
    @error('newStudentGroup') <p class="text-sm font-medium text-danger-ink">{{ $message }}</p> @enderror
</form>
