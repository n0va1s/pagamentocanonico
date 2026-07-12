@props(['title' => null, 'maxWidth' => 'sm'])

<x-layouts::auth.simple :title="$title" :maxWidth="$maxWidth">
    {{ $slot }}
</x-layouts::auth.simple>
