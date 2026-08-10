<?php
/**
 * Script de Emergência para Limpeza de Cache em Produção (Hostinger)
 * Acesse via: https://seusite.com/limpar.php
 */

$bootstrapCacheDir = __DIR__ . '/../bootstrap/cache';
$viewsCacheDir     = __DIR__ . '/../storage/framework/views';
$cacheDir          = __DIR__ . '/../storage/framework/cache/data';

$deletedFiles = 0;

// 1. Apaga arquivos de cache de rotas e configurações (bootstrap/cache/*.php)
if (is_dir($bootstrapCacheDir)) {
    foreach (glob($bootstrapCacheDir . '/*.php') as $file) {
        if (is_file($file) && basename($file) !== '.gitignore') {
            @unlink($file);
            $deletedFiles++;
        }
    }
}

// 2. Apaga views compiladas do Blade (storage/framework/views/*)
if (is_dir($viewsCacheDir)) {
    foreach (glob($viewsCacheDir . '/*') as $file) {
        if (is_file($file) && basename($file) !== '.gitignore') {
            @unlink($file);
            $deletedFiles++;
        }
    }
}

// 3. Tenta rodar o optimize:clear do Laravel via Kernel
try {
    if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
        define('LARAVEL_START', microtime(true));
        require __DIR__ . '/../vendor/autoload.php';
        $app = require_once __DIR__ . '/../bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
        $kernel->call('optimize:clear');
    }
} catch (\Throwable $e) {
    // Ignora se houver erro ao inicializar o Kernel
}

header('Content-Type: text/html; charset=utf-8');
echo "
<!DOCTYPE html>
<html lang='pt-BR'>
<head>
    <meta charset='UTF-8'>
    <title>Cache Limpo com Sucesso</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #f4f4f5; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .card { background: white; padding: 2rem; border-radius: 1rem; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); max-width: 480px; text-align: center; }
        .icon { font-size: 3rem; margin-bottom: 1rem; }
        h1 { color: #16a34a; font-size: 1.5rem; margin-bottom: 0.5rem; }
        p { color: #52525b; font-size: 0.95rem; line-height: 1.5; }
        .btn { display: inline-block; margin-top: 1.5rem; background: #2563eb; color: white; padding: 0.75rem 1.5rem; border-radius: 0.5rem; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
    <div class='card'>
        <div class='icon'>✨</div>
        <h1>Caches de Produção Limpos!</h1>
        <p>Foram removidos <strong>{$deletedFiles} arquivos de cache</strong> de rotas, views compiladas e configurações do servidor.</p>
        <p>Agora acesse o sistema utilizando <strong>Aba Anônima</strong> ou pressione <strong>Ctrl + F5</strong> para ver o Dashboard atualizado.</p>
        <a href='/dashboard' class='btn'>Ir para o Dashboard</a>
    </div>
</body>
</html>
";
