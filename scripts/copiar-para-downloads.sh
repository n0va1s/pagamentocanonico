#!/bin/bash
set -euo pipefail

ZIP="projeto-producao.zip"

# Garante que o arquivo existe antes de tentar copiar.
if [ ! -f "$ZIP" ]; then
    echo "ERRO: '$ZIP' não encontrado. Rode primeiro: bash preparar-producao.sh"
    exit 1
fi

# Descobre o usuário do Windows. O usuário do Windows pode ser diferente do
# usuário do WSL, então perguntamos ao próprio Windows via cmd.exe.
WIN_USER="$(cmd.exe /c "echo %USERNAME%" 2>/dev/null | tr -d '\r\n')"

if [ -z "$WIN_USER" ]; then
    echo "ERRO: não foi possível detectar o usuário do Windows."
    echo "Edite este script e defina DEST manualmente, ex.:"
    echo '  DEST="/mnt/c/Users/SEU_USUARIO/Downloads"'
    exit 1
fi

DEST="/mnt/c/Users/${WIN_USER}/Downloads"

if [ ! -d "$DEST" ]; then
    echo "ERRO: pasta de Downloads não encontrada em: $DEST"
    echo "Edite este script e ajuste a variável DEST manualmente."
    exit 1
fi

cp "$ZIP" "$DEST/"

echo "Copiado com sucesso para: $DEST/$ZIP"
