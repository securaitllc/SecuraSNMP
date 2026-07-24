<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml" />
  <link rel="icon" href="{{ asset('favicon-32x32.png') }}" sizes="32x32" type="image/png" />
  <link rel="icon" href="{{ asset('favicon-16x16.png') }}" sizes="16x16" type="image/png" />
  <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any" />
  <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}" sizes="180x180" />
  <link rel="manifest" href="{{ asset('site.webmanifest') }}" />
  <meta name="theme-color" content="#161A21" />
  <meta name="robots" content="noindex, nofollow" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Nodus</title>
  <link rel="stylesheet" type="text/css" href="{{ asset('loader.css') }}" />
  @vite(['resources/ts/main.ts'])
</head>

<body>
  <div id="app">
    <div id="loading-bg">
      <div class="loading-logo">
        <!-- Nodus mark -->
        <img src="{{ asset('favicon.svg') }}" width="64" height="64" alt="Nodus" />
      </div>
      <div class=" loading">
        <div class="effect-1 effects"></div>
        <div class="effect-2 effects"></div>
        <div class="effect-3 effects"></div>
      </div>
    </div>
  </div>
  
  <script>
    const loaderColor = localStorage.getItem('materialize-initial-loader-bg') || '#FFFFFF'
    const primaryColor = localStorage.getItem('materialize-initial-loader-color') || '#2BA24E'

    if (loaderColor)
      document.documentElement.style.setProperty('--initial-loader-bg', loaderColor)
    if (loaderColor)
      document.documentElement.style.setProperty('--initial-loader-bg', loaderColor)

    if (primaryColor)
      document.documentElement.style.setProperty('--initial-loader-color', primaryColor)
  </script>
</body>

</html>
