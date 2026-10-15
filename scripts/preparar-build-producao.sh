#!/bin/bash
set -euo pipefail

# ----------------------------------------------------------------------------
# Detecta o executor. Preferimos o Laravel Sail quando disponível: ele garante
# o ambiente Linux correto (Node, PHP e dependências nativas do container),
# evitando build quebrada por diferenças do npm/php instalados no WSL/host.
# ----------------------------------------------------------------------------
if [ -f "./vendor/bin/sail" ]; then
    USE_SAIL=1
    RUN="./vendor/bin/sail"
    echo "Laravel Sail detectado. Os comandos rodarão dentro do container."
else
    USE_SAIL=0
    RUN=""
    echo "Laravel Sail não encontrado. Usando ferramentas locais (host/WSL)."
fi

echo "[1/4] Compilando assets do Front-end..."
if [ "$USE_SAIL" -eq 1 ]; then
    $RUN npm install
    $RUN npm run build
elif command -v npm &> /dev/null && npm --version &> /dev/null; then
    npm install
    npm run build
else
    echo "ERRO: npm não encontrado. Instale o Node.js ou use o Laravel Sail."
    exit 1
fi

echo "[2/4] Limpando caches antigos do Laravel..."
$RUN php artisan clear-compiled
# Força o store de cache 'array' para o cache:clear não depender de uma
# conexão (ex.: database/redis) que pode não estar pronta.
# O Sail aceita apenas subcomandos conhecidos (php, artisan, etc.) ou
# 'sail exec <serviço>', então usamos 'exec' para injetar a variável no
# container. No modo local, usamos o prefixo "env VAR=valor" normal.
if [ "$USE_SAIL" -eq 1 ]; then
    $RUN exec -e CACHE_STORE=array laravel.test php artisan cache:clear
else
    CACHE_STORE=array php artisan cache:clear
fi
$RUN php artisan config:clear
$RUN php artisan route:clear
$RUN php artisan view:clear

echo "[3/4] Ignorando geração de cache local..."
# IMPORTANTE: Nunca gere os caches (config:cache, route:cache) no ambiente de dev
# para enviar para produção, pois eles gravam caminhos absolutos locais!
# Esses comandos devem ser rodados no servidor de produção.

echo "[4/4] Criando arquivo ZIP para a Hostinger..."
rm -f projeto-producao.zip

# Argumentos do zip:
# -r  recursivo
# -y  armazena os symlinks como symlinks (não segue públicos como public/storage),
#     evitando copiar a pasta do ambiente de desenvolvimento inteira para o zip.
ZIP_ARGS=(-ry projeto-producao.zip . -x "*.git*" "*.github*" "*.agent*" "*.kiro*" "*node_modules*" "*vendor*" "*tests*" "projeto-producao.zip" "preparar-producao.bat" "preparar-producao.sh" "build-zip.php" "build-zip.ps1" ".env*" ".gitattributes" ".gitignore" "compose.yaml" "phpunit.xml" "README.md" ".clinerules" ".editorconfig" "bootstrap/cache/*.php")

if command -v zip &> /dev/null; then
    zip "${ZIP_ARGS[@]}"
elif [ "$USE_SAIL" -eq 1 ]; then
    echo "'zip' não encontrado no host. Usando o container Laravel Sail..."
    # O Sail só aceita subcomandos conhecidos, então o 'zip' precisa rodar via
    # 'sail exec'. Como o container monta o projeto em /var/www/html, o arquivo
    # gerado aparece na raiz do projeto aqui no host.
    $RUN exec laravel.test zip "${ZIP_ARGS[@]}"
else
    echo "ERRO: comando 'zip' não encontrado. Instale com: sudo apt-get install zip"
    exit 1
fi

# Valida que o ZIP foi realmente gerado antes de declarar sucesso.
if [ ! -f projeto-producao.zip ]; then
    echo "ERRO: o arquivo projeto-producao.zip não foi criado. Verifique os erros acima."
    exit 1
fi

echo "Executado com sucesso! O arquivo projeto-producao.zip está pronto na raiz."
