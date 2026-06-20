<x-layouts::app>
    <div class="max-w-xl mx-auto py-12 px-4">
        {{-- Breadcrumbs --}}
        <div class="flex items-center gap-2 mb-6 text-sm text-zinc-500">
            <flux:link :href="route('dashboard')" wire:navigate>Dashboard</flux:link>
            <flux:icon.chevron-right class="size-3" />
            <span>Importar Extrato OFX</span>
        </div>

        <flux:heading size="xl" class="mb-2">Importar Extrato OFX</flux:heading>
        <flux:subheading class="mb-6">Importe arquivos de extrato bancário no formato OFX para processar transações e acompanhar a adimplência dos associados.</flux:subheading>

        @if(session('error'))
            <flux:callout variant="danger" icon="exclamation-triangle" heading="{{ session('error') }}" class="mb-4" />
        @endif

        @if(session('success'))
            <flux:callout variant="success" icon="check-circle" heading="{{ session('success') }}" class="mb-4" />
        @endif

        <flux:card class="p-6">
            <form action="{{ route('upload.store') }}" method="POST" enctype="multipart/form-data" class="space-y-6">
                @csrf

                <div class="space-y-3">
                    <flux:label for="ofx_file" class="font-semibold text-zinc-700 dark:text-zinc-300">Selecione o arquivo OFX</flux:label>
                    
                    <div class="flex items-center justify-center w-full">
                        <label for="ofx_file" class="flex flex-col items-center justify-center w-full h-44 border-2 border-zinc-300 border-dashed rounded-xl cursor-pointer bg-zinc-50 dark:hover:bg-zinc-800/40 dark:bg-zinc-900/10 hover:bg-zinc-100/50 dark:border-zinc-700 transition">
                            <div class="flex flex-col items-center justify-center pt-5 pb-6">
                                <flux:icon name="arrow-up-tray" class="w-10 h-10 mb-3 text-blue-600 dark:text-blue-500" />
                                <p class="mb-2 text-sm text-zinc-600 dark:text-zinc-400 font-medium">
                                    Clique para escolher ou arraste o arquivo aqui
                                </p>
                                <p class="text-xs text-zinc-500 dark:text-zinc-500">
                                    Apenas arquivos .ofx, .txt ou .xml (Máx: 5MB)
                                </p>
                            </div>
                            <input id="ofx_file" name="ofx_file" type="file" class="hidden" accept=".ofx,.txt,.xml" />
                        </label>
                    </div>

                    @error('ofx_file')
                        <p class="text-xs text-red-500 mt-1 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Selected File Display Script --}}
                <div id="file-info-container" class="hidden flex items-center justify-between p-3 bg-blue-50/50 dark:bg-blue-900/10 border border-blue-100 dark:border-blue-900/30 rounded-lg text-sm text-blue-800 dark:text-blue-300">
                    <div class="flex items-center gap-2 min-w-0">
                        <flux:icon name="document" class="size-5 shrink-0" />
                        <span id="file-name-text" class="truncate font-semibold"></span>
                    </div>
                    <button type="button" onclick="resetFile()" class="text-xs font-bold text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        Remover
                    </button>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <flux:button type="submit" variant="primary" class="px-5">
                        Processar e Importar
                    </flux:button>
                    <flux:button href="{{ route('dashboard') }}" variant="ghost">
                        Cancelar
                    </flux:button>
                </div>
            </form>
        </flux:card>
    </div>

    <script>
        const fileInput = document.getElementById('ofx_file');
        const fileInfoContainer = document.getElementById('file-info-container');
        const fileNameText = document.getElementById('file-name-text');

        fileInput.addEventListener('change', function(e) {
            if (e.target.files && e.target.files.length > 0) {
                const file = e.target.files[0];
                fileNameText.textContent = `${file.name} (${(file.size / 1024).toFixed(1)} KB)`;
                fileInfoContainer.classList.remove('hidden');
            } else {
                resetFile();
            }
        });

        function resetFile() {
            fileInput.value = '';
            fileNameText.textContent = '';
            fileInfoContainer.classList.add('hidden');
        }
    </script>
</x-layouts::app>
