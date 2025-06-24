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
3. A new page template will be available for login and event interaction.
4. Admins can manage events and users via the WordPress admin menu.

## Tables Created

- `wp_zg_events` – Stores each event and its structure.
- `wp_zg_users` – Stores authorized users.
- `wp_zg_reservations` – Stores section-level subscriptions.
- `wp_zg_capacity` – Stores capacity limits per section.

## License
