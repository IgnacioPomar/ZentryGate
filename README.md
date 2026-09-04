# ZentryGate

ZentryGate is a WordPress plugin designed for managing multi-day, multi-section events with limited access and capacity control. It features:

- Custom login with cookie-based session control.
- Role-based behavior (admins vs users).
- Multi-event support with configurable subscription sections and rules (JSON).
- Waiting list system with chronological prioritization.
- Manual and CSV-based user management.
- Admin tools for attendance exports and capacity adjustment.
- Conditional logic for rewards or paid options (e.g., free meals or Stripe payments for dinners).

## Installation

1. Copy the plugin folder `zentrygate` into your `wp-content/plugins/` directory.
2. Activate the plugin from the WordPress admin panel.
3. The active theme must provide the ZentryGate page template (e.g. `Template Name: Zentrygate Inscription Form`) and it must be assigned to a page via Page Attributes; the login/registration flow then continues on a separate, independently configured page.
4. Admins can manage events and users via the WordPress admin menu.

If you install from a release ZIP produced by `generar_zips.sh`, dependencies are already
bundled. If you install from a clone of this repository, run `composer install` in the
plugin root first - without it the Stripe SDK is missing and payments fail silently.

## Stripe setup

1. Make sure the Stripe SDK is present (see above). It is declared in `composer.json` and
   pinned in `composer.lock`; `vendor/` is not versioned.
2. Go to **WP Admin -> ZentryGate -> Stripe** and fill in the publishable key, the secret
   key and the webhook signing secret. They are stored in the `zentrygate_stripe_settings`
   option.
3. In the Stripe dashboard, register a webhook endpoint pointing to:
   `https://<your-site>/wp-json/zentrygate/v1/stripe/webhook`
4. The `wp_zgStripeEvents` table is created by the activation hook. If the plugin was
   already active, deactivate and reactivate it.

> **Upgrading from a pre-Composer install:** earlier versions expected the SDK to be
> unzipped by hand into `vendor/stripe-php/`. WordPress' own update flows ("Update now",
> auto-updates, or uploading the ZIP and choosing "Replace current with uploaded") delete
> the whole plugin directory first, so that stale folder goes away on its own. It only
> survives if you deploy by copying files over the old ones (FTP, `rsync` without
> `--delete`); nothing references it any more, so it is dead weight rather than a
> conflict, but it is worth removing.

## Tables Created

- `wp_zgEvents` – Stores each event and its structure.
- `wp_zgUsers` – Stores authorized users.
- `wp_zgReservations` – Stores section-level subscriptions.
- `wp_zgCapacity` – Stores capacity limits per section.
- `wp_zgStripeEvents` – Stores received Stripe webhook events (idempotency + audit).

## License
