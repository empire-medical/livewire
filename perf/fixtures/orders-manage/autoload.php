<?php

// PSR-4 loader for Livewire\Performance\Fixtures\ (this directory), so the fixture
// works without touching composer.json. Include once: require __DIR__.'/autoload.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'Livewire\\Performance\\Fixtures\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

    if (is_file($file)) {
        require $file;
    }
});
