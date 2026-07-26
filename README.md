# Paymenter Virtualmin Server Extension

Provision, suspend, unsuspend, upgrade, and terminate Virtualmin virtual servers directly from Paymenter — no need to pay for a proxy/relay extension.

## Requirements

- Paymenter (latest version)
- A Virtualmin (GPL or Pro) server with the **Remote API** enabled for the admin/user you'll use
- PHP 8.1+ (same as your Paymenter install)

## Installation

- **Manual:** Download the code and place it in the `app/extensions/Servers/Virtualmin` directory (or `extensions/Servers/Virtualmin` depending on your Paymenter version).
- **Automatic:**
  ```bash
  git clone https://github.com/yourusername/Paymenter-Virtualmin /var/www/paymenter/extensions/Servers/Virtualmin
  ```

Then, from your Paymenter install directory:

```bash
php artisan extensions:sync   # or: php artisan app:extension:sync, depending on version
php artisan cache:clear
```

Finally, go to **Admin Panel > Extensions > Servers**, find **Virtualmin**, and click **Enable**.

## Configuration

1. On your Virtualmin server, go to **Webmin > Webmin Users**, select the user you want to use (or create one), and under **Module Access Control** make sure **Remote API** is allowed, plus access to the **Virtualmin Virtual Servers** module.
2. In Paymenter, go to **Admin Panel > Extensions > Servers > Virtualmin > Configure** and fill in:
   - **Virtualmin Hostname / IP** — e.g. `https://panel.example.com`
   - **Port** — default `10000`
   - **Master Admin Username / Password**
   - **Verify SSL Certificate** — disable only if using a self-signed cert
3. Save.

## Setting up a Product

1. Create a new product under **Admin Panel > Products**, set its type/server to **Virtualmin**.
2. Under **Product Configuration**, either:
   - Enter an existing **Virtualmin Account Plan** name (recommended, keeps quota/bandwidth managed inside Virtualmin), **or**
   - Leave it empty and set **Disk Quota** / **Bandwidth Limit** directly, and choose which **Features** (web, dns, mail, mysql, ssl, webmin) should be enabled.

## Usage

When a customer orders the product, they'll be asked for a **Domain** at checkout. Paymenter will then:

- **On order/create** → create the domain in Virtualmin (`create-domain`) with an auto-generated username/password
- **On suspend** → disable the domain (`disable-domain`)
- **On unsuspend** → re-enable the domain (`enable-domain`)
- **On upgrade/downgrade** → update quota/bandwidth or plan (`modify-domain`)
- **On terminate** → delete the domain (`delete-domain`)

The generated username and password are shown to the customer on the service page in the client area, along with a button to log in to Virtualmin.

## Notes / Caveats

- This extension calls Virtualmin's Remote API (`/virtual-server/remote.cgi`) the same way the `create-domain.pl` / `modify-domain.pl` CLI scripts work. Parameter names (`quota`, `bandwidth`, feature flags, etc.) can vary slightly between Virtualmin GPL/Pro versions — check **System Information > Module Config** or your version's remote API docs if a call fails.
- Make sure port `10000` (or whatever port you use) is reachable from your Paymenter server.
- Passwords are stored as a service property in Paymenter's database — treat your Paymenter DB as sensitive, same as with any other panel integration.

## License

MIT