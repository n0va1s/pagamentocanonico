@props([
    'showAllOption' => false,
    'allOptionLabel' => 'Todas as Associações',
    'placeholder' => 'Selecione a Associação...',
    'associacoes' => null,
])

@php
    $items = $associacoes ?? \App\Models\Associacao::orderBy('nom_associacao')->get();
@endphp

<flux:select {{ $attributes }}>
    @if($showAllOption)
        <flux:select.option value="">{{ $allOptionLabel }}</flux:select.option>
    @elseif($placeholder)
        <flux:select.option value="">{{ $placeholder }}</flux:select.option>
    @endif

    @foreach($items as $assoc)
        <flux:select.option value="{{ $assoc->idt_associacao }}">
            {{ $assoc->nom_associacao }}
        </flux:select.option>
    @endforeach
</flux:select>
