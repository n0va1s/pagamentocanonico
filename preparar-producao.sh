#!/bin/bash

echo "[1/4] Compilando assets do Front-end..."
if command -v npm &> /dev/null && npm --version &> /dev/null; then
    npm install && npm run build
elif [ -f "./vendor/bin/sail" ]; then
    echo "NPM local não encontrado ou com erro (WSL). Usando Laravel Sail..."
    ./vendor/bin/sail npm install && ./vendor/bin/sail npm run build
else
    echo "ERRO: npm não encontrado. Instale o Node.js ou use o Laravel Sail."
    exit 1
fi

echo "[2/4] Limpando caches antigos do Laravel..."
php artisan clear-compiled
CACHE_STORE=array php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

echo "[3/4] Ignorando geração de cache local..."
# IMPORTANTE: Nunca gere os caches (config:cache, route:cache) no ambiente de dev 
# para enviar para produção, pois eles gravam caminhos absolutos locais!
# Esses comandos devem ser rodados no servidor de produção.

echo "[4/4] Criando arquivo ZIP para a Hostinger..."
rm -f projeto-producao.zip
# O parâmetro -y impede que symlinks (como public/storage) sejam seguidos,
# evitando que a pasta do ambiente de desenvolvimento seja copiada inteira para o zip.
zip -ry projeto-producao.zip . -x "*.git*" "*.github*" "*.agent*" "*.kiro*" "*node_modules*" "*vendor*" "*tests*" "projeto-producao.zip" "preparar-producao.bat" "preparar-producao.sh" "build-zip.php" "build-zip.ps1" ".env*" ".gitattributes" ".gitignore" "compose.yaml" "phpunit.xml" "README.md" ".clinerules" ".editorconfig" "bootstrap/cache/*.php"

echo "Executado com sucesso! O arquivo projeto-producao.zip está pronto na raiz."
