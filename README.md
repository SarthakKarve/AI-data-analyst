# 🏦 AI Based Credit Scoring & Risk Assessment Platform

AI CIBIL Store is a PHP and MySQL-based web application designed to help users manage their financial activities, estimate CIBIL-like credit scores, monitor loans, organize documents, and receive personalized financial recommendations. The platform combines financial analytics, secure data management, and AI-driven insights within a modern web interface.

---

## 🚀 Features

### 🔐 Authentication & Security

* Secure email and password authentication
* Password hashing using PHP security functions
* Session-based authentication and access control
* Demo Google Sign-In integration
* CSRF protection for form submissions

### 📊 Dashboard & Analytics

* Interactive dashboard with financial KPIs
* Credit score overview
* Loan and reminder statistics
* Personalized recommendations and insights

### 💳 Loan Management

* Create, update, and delete loans
* EMI calculation using fixed-rate formulas
* Automatic due date generation
* Next installment reminders
* CSV export functionality

### 📄 Document Management

* Secure document upload and download
* File metadata tracking
* Optional AWS S3 cloud storage support
* Document statistics and pagination

### ⏰ Reminder System

* Create and manage reminders
* Link reminders with loans
* Status tracking:

  * Upcoming
  * Completed
  * High Priority
* Dashboard notification badges

### 🛡️ Insurance Analysis

* Insurance eligibility assessment
* Claim probability estimation
* Coverage recommendation engine
* Health and income-based suggestions

### 🤖 AI Chatbot

* Context-aware financial assistant
* Finance terminology explanations
* EMI calculator
* Loan and reminder summaries
* File text extraction and review

### 📈 AI CIBIL Predictor

* Predicts CIBIL-like scores using financial metrics
* Manual and database-driven predictions
* Stores prediction history
* Generates improvement recommendations

---

## 🛠️ Technology Stack

| Category         | Technologies                    |
| ---------------- | ------------------------------- |
| Frontend         | HTML5, Tailwind CSS, JavaScript |
| Backend          | PHP 8+                          |
| Database         | MySQL                           |
| Icons            | Remixicon                       |
| Database Access  | PDO                             |
| Optional Storage | AWS S3                          |

---

## 📁 Project Structure

```text
AI-CIBIL-Store/
│
├── includes/              # Shared components and configurations
├── db.php                 # Database connection
├── login.php              # User login
├── register.php           # User registration
├── logout.php             # Logout functionality
├── index.php              # Dashboard
├── loans.php              # Loan management
├── reminders.php          # Reminder management
├── documents.php          # Document management
├── predictor.php          # CIBIL score prediction
├── insurance.php          # Insurance analysis
├── chatbot.php            # AI chatbot
├── profile.php            # User profile
├── database.txt           # Database schema
└── README.md
```

---

## ⚙️ Installation & Setup

### Prerequisites

* XAMPP/WAMP/LAMP
* PHP 8+
* MySQL
* Apache Server

### Step 1: Clone the Repository

```bash
git clone https://github.com/your-username/AI-CIBIL-Store.git
```

### Step 2: Move Project

Copy the project folder into:

```text
xampp/htdocs/
```

### Step 3: Start Services

Start:

* Apache
* MySQL

from the XAMPP Control Panel.

### Step 4: Create Database

Create a database named:

```sql
CREATE DATABASE cibil;
```

### Step 5: Import Schema

Import `database.txt` using:

* phpMyAdmin
* MySQL CLI

### Step 6: Configure Database

Update credentials inside:

```php
db.php
```

Example:

```php
$host = "127.0.0.1";
$dbname = "cibil";
$username = "root";
$password = "";
```

### Step 7: Run Application

Open:

```text
http://localhost/cibil/login.php
```

---

## 🔒 Security Features

* Session-based authentication
* CSRF token validation
* Password hashing
* PDO prepared statements
* SQL injection prevention
* HTML output escaping
* Protected routes for authenticated users

---

## 💡 Working Principles

### EMI Calculation

The system calculates EMI using:

```text
EMI = P × r × (1+r)^n / ((1+r)^n - 1)
```

Where:

* **P** = Principal Amount
* **r** = Monthly Interest Rate
* **n** = Loan Tenure (Months)

---

### Credit Score Prediction Logic

The AI prediction module analyzes:

* Credit utilization ratio
* EMI-to-income ratio
* Loan count
* Average interest rates
* Pending reminders
* Credit age
* Document completeness

Predicted scores are maintained within the standard range:

```text
300 – 900
```

---

## 🤖 Machine Learning Roadmap

Future versions may include:

* Linear Regression models
* Random Forest models
* Gradient Boosting models
* SHAP explainability
* Feature importance visualization
* Real-time prediction APIs

---

## ☁️ Optional AWS S3 Integration

To enable cloud document storage:

Set environment variables:

```powershell
setx S3_ENABLED true
setx AWS_BUCKET your-bucket-name
setx AWS_REGION your-region
setx AWS_ACCESS_KEY_ID your-access-key
setx AWS_SECRET_ACCESS_KEY your-secret-key
```

Install AWS SDK for PHP before enabling this feature.

---

## 🎯 Core Modules

| Module    | Description                       |
| --------- | --------------------------------- |
| Dashboard | Financial overview and insights   |
| Loans     | Loan tracking and EMI calculation |
| Documents | Secure file storage               |
| Reminders | Payment reminders                 |
| Predictor | AI-based CIBIL estimation         |
| Insurance | Coverage and eligibility analysis |
| Chatbot   | Context-aware financial assistant |
| Profile   | User profile management           |

---

## 🧩 Database Tables

Main tables include:

* `users`
* `loans`
* `cibil_scores`
* `documents`
* `reminders`
* `insurance`
* `chat_messages`
* `activity_log`
* `recommendations`
* `tutorials_library`

---

## 🚧 Future Enhancements

* Real Machine Learning model integration
* Fraud detection system
* Financial analytics dashboard
* Notification system (Email/SMS)
* Advanced chatbot capabilities
* REST API support
* Audit logging enhancements
* Unified migration system

---

## 🤝 Contributing

Contributions are welcome.

1. Fork the repository.
2. Create a new branch.

```bash
git checkout -b feature-name
```

3. Commit changes.

```bash
git commit -m "Added new feature"
```

4. Push changes.

```bash
git push origin feature-name
```

5. Create a Pull Request.

---

## 📜 License

This project is licensed under the MIT License.

---

## 👨‍💻 Author

**Sarthak Karve**

GitHub: https://github.com/SarthakKarve
