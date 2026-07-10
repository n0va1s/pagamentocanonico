#!/bin/bash

echo "[1/5] Compilando assets do Front-end..."
npm install && npm run build

echo "[2/5] Limpando caches antigos do Laravel..."
php artisan clear-compiled
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

echo "[3/5] Otimizando dependencias do Composer..."
composer install --optimize-autoloader --no-dev

echo "[4/5] Ignorando geração de cache local..."
# IMPORTANTE: Nunca gere os caches (config:cache, route:cache) no ambiente de dev 
# para enviar para produção, pois eles gravam caminhos absolutos locais!
# Esses comandos devem ser rodados no servidor de produção.

echo "[5/5] Criando arquivo ZIP para a Hostinger..."
rm -f projeto-producao.zip
# O parâmetro -y impede que symlinks (como public/storage) sejam seguidos,
# evitando que a pasta do ambiente de desenvolvimento seja copiada inteira para o zip.
zip -ry projeto-producao.zip . -x "*.git*" "*.github*" "*.agent*" "*.kiro*" "*node_modules*" "*tests*" "projeto-producao.zip" "preparar-producao.bat" "preparar-producao.sh" "build-zip.php" "build-zip.ps1" ".env*" ".gitattributes" ".gitignore" "compose.yaml" "phpunit.xml" "README.md" ".clinerules" ".editorconfig" "bootstrap/cache/*.php"

echo "Executado com sucesso! O arquivo projeto-producao.zip está pronto na raiz."
echo "Dica: Caso queira restaurar as dependências locais de desenvolvimento, execute:"
echo "composer install"

