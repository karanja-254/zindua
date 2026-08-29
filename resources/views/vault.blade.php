<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
        <title>Fruit Ninja Dojo</title>
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/witnessvault/main.jsx'])
    </head>
    <body>
        <div id="vault-root"></div>
    </body>
</html>
