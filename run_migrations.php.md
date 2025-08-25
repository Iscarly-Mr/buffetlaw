# Error - Internal Server Error
Class "Faker\Factory" not found

PHP 8.3.24
Laravel 12.25.0
localhost

## Stack Trace

0 - vendor/laravel/framework/src/Illuminate/Database/DatabaseServiceProvider.php:103
1 - vendor/laravel/framework/src/Illuminate/Container/Container.php:1153
2 - vendor/laravel/framework/src/Illuminate/Container/Container.php:971
3 - vendor/laravel/framework/src/Illuminate/Foundation/Application.php:1077
4 - vendor/laravel/framework/src/Illuminate/Container/Container.php:902
5 - vendor/laravel/framework/src/Illuminate/Foundation/Application.php:1057
6 - vendor/laravel/framework/src/Illuminate/Database/Eloquent/Factories/Factory.php:957
7 - vendor/laravel/framework/src/Illuminate/Database/Eloquent/Factories/Factory.php:184
8 - vendor/laravel/framework/src/Illuminate/Database/Eloquent/Factories/Factory.php:204
9 - vendor/laravel/framework/src/Illuminate/Database/Eloquent/Factories/Factory.php:916
10 - vendor/laravel/framework/src/Illuminate/Database/Eloquent/Factories/HasFactory.php:21
11 - database/seeders/DatabaseSeeder.php:18
12 - vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php:36
13 - vendor/laravel/framework/src/Illuminate/Container/Util.php:43
14 - vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php:96
15 - vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php:35
16 - vendor/laravel/framework/src/Illuminate/Container/Container.php:835
17 - vendor/laravel/framework/src/Illuminate/Database/Seeder.php:188
18 - vendor/laravel/framework/src/Illuminate/Database/Seeder.php:197
19 - vendor/laravel/framework/src/Illuminate/Database/Console/Seeds/SeedCommand.php:71
20 - vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/GuardsAttributes.php:157
21 - vendor/laravel/framework/src/Illuminate/Database/Console/Seeds/SeedCommand.php:70
22 - vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php:36
23 - vendor/laravel/framework/src/Illuminate/Container/Util.php:43
24 - vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php:96
25 - vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php:35
26 - vendor/laravel/framework/src/Illuminate/Container/Container.php:835
27 - vendor/laravel/framework/src/Illuminate/Console/Command.php:211
28 - vendor/symfony/console/Command/Command.php:318
29 - vendor/laravel/framework/src/Illuminate/Console/Command.php:180
30 - vendor/symfony/console/Application.php:1092
31 - vendor/symfony/console/Application.php:341
32 - vendor/symfony/console/Application.php:192
33 - vendor/laravel/framework/src/Illuminate/Console/Application.php:165
34 - vendor/laravel/framework/src/Illuminate/Foundation/Console/Kernel.php:426
35 - run_migrations.php:16

## Request

GET /

## Headers

* **host**: localhost
* **user-agent**: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36
* **accept**: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7
* **accept-language**: es-ES,es;q=0.9,en;q=0.8
* **accept-charset**: ISO-8859-1,utf-8;q=0.7,*;q=0.7
* **accept-encoding**: gzip, deflate, br, zstd
* **sec-ch-ua**: "Not;A=Brand";v="99", "Google Chrome";v="139", "Chromium";v="139"
* **sec-ch-ua-mobile**: ?0
* **sec-ch-ua-platform**: "Windows"
* **upgrade-insecure-requests**: 1
* **sec-fetch-site**: none
* **sec-fetch-mode**: navigate
* **sec-fetch-user**: ?1
* **sec-fetch-dest**: document
* **priority**: u=0, i
* **x-https**: 1

## Route Context

No routing data available.

## Route Parameters

No route parameter data available.

## Database Queries

* sqlite - select exists (select 1 from "main".sqlite_master where name = 'migrations' and type = 'table') as "exists" (1.18 ms)
* sqlite - select exists (select 1 from "main".sqlite_master where name = 'migrations' and type = 'table') as "exists" (0.07 ms)
* sqlite - select "migration" from "migrations" order by "batch" asc, "migration" asc (0.08 ms)
* sqlite - select "migration" from "migrations" order by "batch" asc, "migration" asc (0.07 ms)
