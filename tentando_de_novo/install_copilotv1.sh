#!/usr/bin/env bash
set -e

echo
echo "========== CopilotV1 Installer =========="
echo

# 1) Verifica diretório
if [ ! -f artisan ]; then
  echo "Erro: execute este script dentro da pasta raiz do projeto (onde está o 'artisan')."
  exit 1
fi

# 2) Pergunta dados ao usuário
read -p "APP_URL (ex: https://example.com/tools/copilotv1): " APP_URL
read -p "DB_HOST (ex: localhost): " DB_HOST
read -p "DB_PORT (ex: 3306): " DB_PORT
read -p "DB_DATABASE: " DB_DATABASE
read -p "DB_USERNAME: " DB_USERNAME
read -sp "DB_PASSWORD: " DB_PASSWORD; echo
read -p "OPENAI_API_KEY: " OPENAI_API_KEY

echo
echo "Gerando .env com suas configurações..."

# 3) Cria .env
cp .env.example .env
sed -i "s|APP_URL=.*|APP_URL=$APP_URL|" .env
sed -i "s/DB_HOST=.*/DB_HOST=$DB_HOST/" .env
sed -i "s/DB_PORT=.*/DB_PORT=$DB_PORT/" .env
sed -i "s/DB_DATABASE=.*/DB_DATABASE=$DB_DATABASE/" .env
sed -i "s/DB_USERNAME=.*/DB_USERNAME=$DB_USERNAME/" .env
sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=$DB_PASSWORD|" .env
sed -i "s|OPENAI_API_KEY=.*|OPENAI_API_KEY=$OPENAI_API_KEY|" .env

# 4) Instala dependências PHP
echo
echo "Instalando dependências PHP (composer)..."
composer install --no-interaction --optimize-autoloader

# 5) Gera chave de aplicativo
echo
echo "Gerando APP_KEY..."
php artisan key:generate --ansi

# 6) Executa migrations e seeders
echo
echo "Rodando migrations e seeders..."
php artisan migrate --force --ansi
php artisan db:seed --force --ansi

# 7) Cria link simbólico de storage
echo
echo "Criando storage link..."
php artisan storage:link --ansi

# 8) Ajusta permissões
echo
echo "Ajustando permissões em storage e bootstrap/cache..."
chown -R www-data:www-data storage bootstrap/cache || true
chmod -R 775 storage bootstrap/cache

# 9) Limpa e cacheia configs, rotas e views
echo
echo "Cacheando configurações, rotas e views..."
php artisan config:cache --ansi
php artisan route:cache --ansi
php artisan view:cache --ansi

echo
echo "✔ Instalação concluída! Acesse: $APP_URL"
echo "—————————————————————————————————"
