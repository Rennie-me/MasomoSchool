<<<<<<< HEAD
# Masomo School Management System

A PHP and MySQL school course enrollment app with student accounts, an administrator dashboard, and KSh 1 M-Pesa checkout. Tailwind CSS is loaded from its CDN, so the browser needs internet access to render the styled interface.

## Requirements

- PHP 8.1+ with PDO MySQL and cURL extensions
- MySQL 5.7+ (or a compatible MariaDB version)
- XAMPP/Apache or another PHP web server
- Safaricom Daraja credentials for live payment use

## Local setup (XAMPP)

1. Copy `.env.example` to `.env` and set your database credentials and `APP_URL` (for example, `http://localhost/MasomoSchoolManagementSystem`).
2. Import `schema.sql` in phpMyAdmin or run `mysql -u root -p < schema.sql`.
3. Configure `ADMIN_EMAIL`, `ADMIN_PASSWORD` (at least 12 characters), and `ADMIN_NAME` in `.env`, then create the administrator from the project directory:

   ```sh
   php scripts/create_admin.php
   ```

   The command creates a new administrator and will not overwrite an existing account.
4. Open `http://localhost/MasomoSchoolManagementSystem/`.
5. To test M-Pesa, configure the Daraja sandbox app credentials, shortcode, and passkey. Add a phone number in the student's profile during registration using a Kenyan format such as `0712345678`.

## M-Pesa callback with ngrok

Start Apache in XAMPP, then expose port 80:

```sh
ngrok http 80
```

Set `MPESA_CALLBACK_URL` in `.env` to the HTTPS ngrok URL followed by `/MasomoSchoolManagementSystem/mpesa_callback.php`, for example `https://example.ngrok-free.app/MasomoSchoolManagementSystem/mpesa_callback.php`. Use that same public callback URL in the Daraja STK Push request configuration. Restart ngrok and update the value whenever its URL changes. Daraja's callback is cross-checked against the STK Push Query API before the payment is marked paid or the enrollment activated.

## Payment behavior

- A real M-Pesa payment activates an enrollment only after a matching Daraja STK Push Query result.
- On a local site configured with `APP_ENV=local` and `MPESA_ENV=sandbox`, a student can simulate a pending sandbox payment from `localhost`. This activates the enrollment for testing only; it does not contact Safaricom or charge money.
- Course materials entered by an administrator are shown only to students with an active (paid) enrollment.
- M-Pesa receives integer KSh 1.
- Payment state updates lock the payment row and only transition pending payments once, so duplicate callbacks cannot activate/process a payment twice.
- Failed or abandoned verified attempts remain inactive. A new attempt can be started after a pending attempt ages out.
- Configure real production credentials and a stable HTTPS callback URL before accepting live payments.

## Security notes

- Keep `.env` private; it is excluded from Git. Never put provider secrets in frontend code.
- The app uses hashed passwords, role checks, CSRF tokens, prepared statements, and server-side payment verification.
- Serve the app over HTTPS in production and use a strong unique administrator password.
=======
# MasomoSchool
>>>>>>> origin/main
