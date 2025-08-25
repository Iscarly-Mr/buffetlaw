#!/usr/bin/env bash

APP="buffetlaw"
ZIPFILE="buffetlaw.zip"

# Remove instalações anteriores
rm -rf $APP $ZIPFILE

# 1. Cria projeto Laravel
composer create-project laravel/laravel $APP --quiet

cd $APP

# 2. Instala dependências
composer require spatie/laravel-permission \
  spatie/laravel-activitylog \
  spatie/laravel-backup \
  barryvdh/laravel-dompdf \
  openai-php/client \
  guzzlehttp/guzzle \
  laravel-notification-channels/whatsapp \
  livewire/livewire --quiet

# 3. Publica providers
php artisan vendor:publish --all --quiet

# 4. Gera key e .env
php artisan key:generate --quiet
cp .env.example .env

# 5. Cria diretórios de storage e logs
mkdir -p storage/backup storage/logs public/uploads

# 6. Executa migrations
php artisan migrate --force --quiet

# 7. Seed inicial de roles/permissions
php artisan db:seed --quiet

cd ..

# 8. Empacota em ZIP
zip -r $ZIPFILE $APP

echo "✔ Projeto '$APP' criado e empacotado em '$ZIPFILE'"
