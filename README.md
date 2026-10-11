# Paradise Studios 🎙️

**Book. Record. Drop. No Turning Back.**

Paradise Studios is a studio-booking and music platform designed to connect artists with recording studios. Users can explore studios, manage bookings, upload music, release tracks, write reviews, and earn experience points through the Buzz XP system.

## 🌐 Live Application

**Website:** https://paradisestudios.siwesone.ng

**GitHub Repository:** https://github.com/Cryptdark1/paradisestudios

## ✨ Key Features

- **User Authentication:** Registration, login, and email verification.
- **Role-Based Access:** Separate permissions for artists, studio owners, and administrators.
- **Studio Discovery:** View available studios, locations, descriptions, and hourly rates.
- **Booking Management:** Create and manage studio bookings with scheduling conflict checks.
- **Track Uploads:** Upload recordings associated with completed bookings.
- **Music Releases:** Release recorded tracks and earn Buzz XP.
- **Reviews and Ratings:** Submit reviews for completed studio bookings.
- **Buzz XP System:** Earn experience points and progress through achievement levels.
- **Owner Dashboard:** Access studio booking information and relevant analytics.

## 🏆 Buzz XP Levels

| Level | Experience Points (XP) |
|---|---:|
| Local | 0–499 |
| City Star | 500–1,999 |
| National Icon | 2,000+ |

## 🛠️ Technology Stack

- **Backend:** Native PHP
- **Database:** MySQL / MariaDB
- **Database Connectivity:** PDO
- **Frontend:** HTML, CSS, JavaScript, Bootstrap, and Gentelella
- **Calendar:** FullCalendar
- **Charts:** Chart.js
- **Email Delivery:** Brevo transactional email API

## 🚀 Getting Started

### Requirements

- PHP with PDO MySQL support
- MySQL or MariaDB
- Apache or another compatible PHP web server
- Git and a modern web browser

### Installation

1. Clone the repository:

   ```bash
   git clone https://github.com/Cryptdark1/paradisestudios.git
   cd paradisestudios
   ```

2. Create a MySQL or MariaDB database and configure a database user.

3. Import the base database schema:

   ```bash
   mysql -u YOUR_DB_USER -p YOUR_DB_NAME < sql/schema.sql
   ```

4. Apply the required database migrations in the correct order, ensuring their prerequisites are satisfied.

5. Configure the database connection, application environment, and email settings for your local environment.

6. Configure your web server to serve the project directory and open the application in your browser.

7. Register an account and verify your email address if email verification is enabled.

**Important:** Never commit database passwords, API keys, or other production secrets to GitHub. Review migration prerequisites before running SQL against an existing database.

## 🔐 Security Notes

- Production access is served over HTTPS.
- Database credentials and API keys should be stored outside the public web directory.
- Production debugging should remain disabled.
- Role-based access controls restrict actions according to the user's role.
- Booking ownership and completion rules are checked by relevant application endpoints.

## 💳 Payment Status

Payment-related functionality exists in the codebase, but live payment processing is currently disabled in the deployment. Do not assume that a booking has been paid unless payment confirmation has been implemented and verified.

## 🧪 Testing Checklist

Test the following using dedicated test accounts and test data:

- Registration, login, and email verification
- Studio listings and studio details
- Booking creation and scheduling conflict checks
- Booking status and ownership restrictions
- Track upload and release
- Review submission and duplicate-review prevention
- Buzz XP updates
- Artist, owner, and administrator permissions

## 📌 Project Status

Paradise Studios is an actively developed project. Features and availability should be verified against the current live deployment before use.

## 📄 License

No open-source license has been specified. Contact the project owner for permission before redistributing or reusing this project's code.
