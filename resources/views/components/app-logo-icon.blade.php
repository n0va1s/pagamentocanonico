<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" {{ $attributes }}>
    <defs>
        <linearGradient id="brand-gradient" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="#2563eb" />
            <stop offset="100%" stop-color="#1d4ed8" />
        </linearGradient>
    </defs>

    {{-- Documento --}}
    <path d="M 160,96 L 304,96 L 384,176 L 384,416 A 32,32 0 0 1 352,448 L 160,448 A 32,32 0 0 1 128,416 L 128,128 A 32,32 0 0 1 160,96 Z"
          fill="none"
          stroke="url(#brand-gradient)"
          stroke-width="24"
          stroke-linejoin="round"
          stroke-linecap="round"/>

    {{-- Dobra do canto --}}
    <path d="M 304,96 L 304,176 L 384,176"
          fill="none"
          stroke="url(#brand-gradient)"
          stroke-width="24"
          stroke-linejoin="round"
          stroke-linecap="round"/>

    {{-- Linhas de texto --}}
    <line x1="180" y1="180" x2="260" y2="180" stroke="url(#brand-gradient)" stroke-width="20" stroke-linecap="round"/>
    <line x1="180" y1="240" x2="332" y2="240" stroke="url(#brand-gradient)" stroke-width="20" stroke-linecap="round"/>

    {{-- Linha de pulso / gráfico --}}
    <path d="M 180,340 L 220,340 L 240,300 L 265,370 L 290,270 L 325,355 L 385,215"
          fill="none"
          stroke="#2563eb"
          stroke-width="28"
          stroke-linecap="round"
          stroke-linejoin="round"/>
</svg>
