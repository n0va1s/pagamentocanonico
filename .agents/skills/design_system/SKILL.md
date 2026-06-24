---
name: design_system
description: Guidelines and patterns for the application's user interface design (Design System) to ensure visual consistency across all listing, dashboard, and form pages.
---

# UI Design System Guidelines

This document serves as the guide for refactoring and creating pages. It details structural layout patterns, typography, components, and coding conventions to ensure UI consistency, mobile-first design, and high accessibility.

## 1. Outer Page Structure
All primary application pages should use a standard outer wrapper container with consistent padding and max width:
```html
<div class="space-y-6 p-6 max-w-7xl mx-auto">
    <!-- Content goes here -->
</div>
```
- On extra small devices, `p-4` or `p-6` offers sufficient breathing room. `space-y-6` provides vertical rhythm.

## 2. Standard Page Header
The page header must consist of a title, icon, a description/subtitle, and right-aligned actions (buttons). It must be responsive (mobile-first):
```html
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100 flex items-center gap-2">
            <flux:icon name="icon-name" class="size-6 text-blue-600" /> Page Title
        </h1>
        <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">
            Short, user-friendly page description explaining its purpose.
        </p>
    </div>
    <!-- Actions (buttons, etc.) align to the right on desktop, stack on mobile -->
    <div class="flex items-center gap-2 self-start sm:self-auto">
        <flux:button variant="primary" icon="plus" href="...">
            Action Button
        </flux:button>
    </div>
</div>
```

## 3. Filters / Search Card
When a listing contains tables, place the filter bar in a unified card above the table. It must adapt to mobile screens:
```html
<flux:card class="flex flex-col sm:flex-row gap-3">
    <div class="flex-1">
        <flux:input
            wire:model.live.debounce.300ms="search"
            placeholder="Search query..."
            icon="magnifying-glass"
            clearable
            aria-label="Buscar"
        />
    </div>
    <!-- Secondary filters stack on mobile, align to the right on desktop -->
    <div class="sm:w-52">
        <flux:select wire:model.live="filter_option" aria-label="Filtro">
            ...
        </flux:select>
    </div>
</flux:card>
```

## 4. Clean Table & List Display
Always wrap tables inside a `flux:card` to provide card backing.
- Paginate all lists to keep pages load times minimal.
- Show clear text or illustrations when the list is empty.
- Place pagination inside the card at the bottom, separated by a top border.
```html
<flux:card class="overflow-x-auto p-0">
    <flux:table>
        <flux:table.columns>
            <flux:table.column>Primary Column</flux:table.column>
            <flux:table.column class="hidden md:table-cell">Responsive Column</flux:table.column>
            <flux:table.column class="text-right">Actions</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($items as $item)
                <flux:table.row wire:key="item-{{ $item->id }}">
                    <flux:table.cell class="font-medium">...</flux:table.cell>
                    <flux:table.cell class="hidden md:table-cell">...</flux:table.cell>
                    <flux:table.cell class="text-right">...</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="3" class="py-12 text-center text-zinc-400">
                        Nenhum registro encontrado.
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    @if ($items->hasPages())
        <div class="px-4 py-3 border-t border-zinc-200 dark:border-zinc-700">
            {{ $items->links() }}
        </div>
    @endif
</flux:card>
```

## 5. Mobile-First & Responsiveness Rules
- **Stacking**: Use `flex-col sm:flex-row` on headers and filter blocks.
- **Hiding columns**: Hide less critical columns on mobile devices using `class="hidden sm:table-cell"` or `class="hidden md:table-cell"`.
- **Card grid**: Use `grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3` for grid structures.
- **Selects / Inputs**: Ensure text sizes on inputs are at least `text-base` (or standard Flux styling) to prevent automatic iOS zoom behaviors.

## 6. High Accessibility (a11y)
- **Labels**: Every input/select should be paired with a visual label or `aria-label`/`aria-labelledby`.
- **Semantic HTML**: Use `<header>`, `<main>`, `<h1>` to `<h6>`, `<form>` tags properly.
- **Contrast**: Use legible text colors (e.g. `text-neutral-800` instead of faint grey).
- **Focus**: Maintain default or customized focus outlines (e.g. `focus-visible:ring-2`) to keep keyboard navigation clear.
