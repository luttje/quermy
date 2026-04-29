# Quermy

<img src="./logo.png" alt="Quermy logo" width="512" align="center">

A drop-in MySQL companion for W/LAMP stacks. Lightweight alternative to
phpMyAdmin with a modern Svelte UI and a small, dependency-free PHP backend.

> [!WARNING]
> This is a prototype, not production-ready software. Use at your own risk.

## Why

phpMyAdmin works, but it shows its age. Quermy keeps the same drop-in
deployment story (one folder under your DocumentRoot), modernises the UI,
and provides AI assistance for query writing and schema exploration (where
you can bring your own API key).

## Quick start

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

The `router.php` script makes the built-in PHP server replicate what
`.htaccess` does under Apache (serve static assets, route `/api/*` to
`index.php`, fall back to the SPA otherwise).

…or drop the `backend/public` directory under your existing Apache
DocumentRoot — the bundled `.htaccess` handles the routing.

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
