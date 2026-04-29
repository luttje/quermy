# <img src="./logo.png" alt="Quermy logo" width="50" align="center"> Quermy

A drop-in MySQL companion for W/LAMP stacks. Lightweight alternative to phpMyAdmin with a modern Svelte UI and a small, dependency-free PHP backend.

> [!WARNING]
> This is a prototype, not production-ready software. Use at your own risk.

<div align="center">

<img src="./screenshot.png" alt="Screenshot of Quermy UI" width="600">

</div>

## Why

phpMyAdmin works, but it shows its age. Quermy is just as ergonomic to install, while it modernises the UI, and provides AI assistance for query writing and schema exploration (where you can bring your own API keys).

## 🧃 Use a pre-built release

For your convenience there are [pre-built releases of Quermy](https://github.com/luttje/quermy/releases), ready to drop into your stack.

### For Laragon users

1. Download the `quermy-laragon-<version>.zip` from the latest release
2. Unzip it
3. Drop the entire `laragon` folder from the zip over your Laragon installation (overwrite when prompted):
    So if you installed Laragon in `C:\laragon`, you drop the folder into `C:\`
4. Restart Apache from the Laragon control panel. <small>[(view Apache alias example)](tools/releases/laragon/quermy.conf)</small>

Quermy will be available at `http://localhost/quermy`.

<details>

<summary>What these steps do and why</summary>

The above steps place Quermy into `<LaragonDirectory>/etc/apps/quermy`, along with an Apache alias file `<LaragonDirectory>/etc/apache2/alias/quermy.conf` that points the URL path `/quermy` to that directory. Laragon automatically includes all alias files in its Apache configuration, so this makes Quermy available without you having to manually edit Apache's config.

</details>

### For tinkerers

1. Download the `quermy-<version>.zip` from the latest release
2. Unzip the file
3. Point your web server at the `public` directory.

## 🚀 Build it yourself

### 1. Build the frontend

```sh
cd frontend
npm install
npm run build   # outputs into ../backend/public
```

### 2. Serve the backend

For local development:

```sh
cd backend
php -S localhost:8000 -t public public/router.php
```

The `router.php` script makes the built-in PHP server replicate what `.htaccess` does under Apache (serve static assets, route `/api/*` to `index.php`, fall back to the SPA otherwise).

Open `http://localhost:8000` and you're in.

### Frontend dev mode

If you want hot-reload while iterating on the UI:

```sh
# terminal 1
cd backend && php -S localhost:8000 -t public

# terminal 2
cd frontend && npm run dev
```

Vite proxies `/api` → `localhost:8000` so cookies behave correctly.
