# Quick Start Guide - Dot.Sheet AI Spreadsheet Platform

## 🎯 Project Overview

Dot.Sheet is an AI-powered spreadsheet web platform built with:
- **Backend**: Laravel 11 with Jetstream (Teams support)
- **Frontend**: Livewire + Alpine.js + Tailwind CSS
- **Database**: SQLite (development)
- **Real-time**: Broadcasting support via Laravel Echo
- **AI Integration**: OpenAI API (configured in Phase 3)

## 📋 Initial Setup

### Prerequisites
- PHP 8.4
- Composer
- Node.js 18+
- npm or yarn

### Installation Steps

```bash
# 1. Clone and navigate to project
cd /workspaces/Dot.Sheet

# 2. Install PHP dependencies (already done)
# composer install

# 3. Install JavaScript dependencies (already done)
# npm install

# 4. Run migrations (already done)
# php artisan migrate

# 5. Start the development server
php artisan serve

# 6. In another terminal, watch for frontend changes
npm run dev
```

The application will be available at `http://localhost:8000`

## 🗄️ Database Schema

### Core Tables
- **spreadsheets** - Main spreadsheet documents
- **cells** - Individual cell data (sparse storage)
- **spreadsheet_user** - Sharing and permissions
- **cell_history** - Audit trail of changes
- **ai_prompts** - AI interaction logs

### Jetstream Tables (Already set up)
- **users** - User accounts with 2FA support
- **teams** - Team management
- **team_user** - Team membership
- **team_invitations** - Team invitations

## 🔐 Authentication & Authorization

### Built-in Features
- Email/password authentication via Fortify
- Two-factor authentication (2FA)
- Team management via Jetstream
- Profile photo uploads
- API tokens via Sanctum

### Spreadsheet Authorization
- **Owner**: Full control (create, edit, delete, share)
- **Team Member**: Edit/view team spreadsheets (based on team role)
- **Shared User**: View/edit/comment (based on permission level)
- **Permission Levels**: view, comment, edit, admin

## 📦 Key Models

```php
// Create a spreadsheet
$spreadsheet = Spreadsheet::create([
    'uuid' => Illuminate\Support\Str::uuid(),
    'owner_id' => auth()->id(),
    'team_id' => auth()->user()->current_team_id,
    'name' => 'Q1 Sales Data',
    'settings' => ['theme' => 'light']
]);

// Add cells to spreadsheet
$cell = Cell::create([
    'spreadsheet_id' => $spreadsheet->id,
    'row_index' => 1,
    'col_index' => 1,
    'raw_value' => '100',
    'formula' => null,
    'formatting' => ['bold' => true, 'color' => '#000000']
]);

// Share spreadsheet with user
$spreadsheet->sharedUsers()->attach($user_id, ['permission' => 'edit']);
```

## 🎨 Frontend Stack

### Components
- **Livewire**: Server-driven reactive components
- **Alpine.js**: Lightweight client-side interactivity
- **Tailwind CSS**: Utility-first CSS framework

### Available Views
- Jetstream dashboard (already via Livewire)
- Team management pages
- Profile settings

### Creating Livewire Components
```bash
php artisan make:livewire ShowSpreadsheet
```

This creates:
- `app/Livewire/ShowSpreadsheet.php` - Component class
- `resources/views/livewire/show-spreadsheet.blade.php` - Blade view

## 🚀 Development Workflow

### Running Commands
```bash
# Start development server
php artisan serve

# Watch CSS/JS for changes
npm run dev

# Build for production
npm run build

# Run tests
php artisan test

# Create new model with migration and factory
php artisan make:model ModelName -mf

# Create new Livewire component
php artisan make:livewire ComponentName
```

### Database Management
```bash
# Run pending migrations
php artisan migrate

# Rollback last migration
php artisan migrate:rollback

# Reset entire database
php artisan migrate:fresh

# Create new migration
php artisan make:migration create_table_name
```

## 📚 Project Structure

```
app/
├── Models/           # Eloquent models
├── Policies/         # Authorization policies
├── Livewire/         # Livewire components (to be created)
├── Http/
│   ├── Controllers/  # Route controllers
│   └── Requests/     # Form requests
└── Providers/        # Service providers

resources/
├── views/
│   ├── layouts/      # Layout templates
│   ├── livewire/     # Livewire component views
│   └── components/   # Reusable components
├── css/              # Tailwind CSS
└── js/               # JavaScript files

database/
├── migrations/       # Database migrations
└── seeders/          # Data seeders

routes/
├── web.php           # Web routes
├── api.php           # API routes
└── channels.php      # Broadcasting channels
```

## 🔗 Important Relationships

```
User
├── has many → Spreadsheet (owner_id)
├── has many → AiPrompt
├── belongs to many → Spreadsheet (shared)
└── has many → Team (TeamUser pivot)

Team
├── has many → Spreadsheet (team_id)
└── has many → User (TeamUser pivot)

Spreadsheet
├── belongs to → User (owner)
├── belongs to → Team (optional)
├── has many → Cell
├── has many → AiPrompt
└── belongs to many → User (shared)

Cell
├── belongs to → Spreadsheet
└── has many → CellHistory

CellHistory
├── belongs to → Cell
└── belongs to → User
```

## 🛠️ Common Tasks

### Create New Spreadsheet
```php
$user = auth()->user();
$spreadsheet = $user->spreadsheets()->create([
    'uuid' => (string) Illuminate\Support\Str::uuid(),
    'name' => 'New Spreadsheet',
    'team_id' => $user->current_team_id,
    'settings' => []
]);
```

### Check Spreadsheet Permission
```php
// Using policy
$user->can('view', $spreadsheet);
$user->can('update', $spreadsheet);

// Direct authorization
Gate::authorize('view', $spreadsheet);
```

### Add Cell to Spreadsheet
```php
$cell = $spreadsheet->cells()->create([
    'row_index' => 0,
    'col_index' => 0,
    'raw_value' => 'Hello',
]);

// Or with formula
$cell = $spreadsheet->cells()->updateOrCreate(
    ['row_index' => 0, 'col_index' => 1, 'spreadsheet_id' => $spreadsheet->id],
    ['formula' => '=A1+B1', 'computed_value' => null]
);
```

### Record Cell Change
```php
$cell->history()->create([
    'user_id' => auth()->id(),
    'old_value' => $cell->raw_value,
    'new_value' => $new_value,
]);
```

## 📖 Documentation Files

- [TASK_LIST.md](TASK_LIST.md) - Full project roadmap (7 phases)
- [PHASE_1_COMPLETION.md](PHASE_1_COMPLETION.md) - Detailed Phase 1 completion report
- [README.md](README.md) - Laravel default README

## 🔗 Useful Resources

- [Laravel Documentation](https://laravel.com/docs)
- [Jetstream Docs](https://jetstream.laravel.com)
- [Livewire Docs](https://livewire.laravel.com)
- [Tailwind CSS Docs](https://tailwindcss.com)
- [Alpine.js Docs](https://alpinejs.dev)

## ⚙️ Environment Configuration

Check `.env` file for important settings:
```
APP_NAME=Dot.Sheet
APP_ENV=local
DB_CONNECTION=sqlite
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```

## 📧 Email Configuration (for notifications)
```
MAIL_MAILER=log    # In development, uses log driver
MAIL_FROM_ADDRESS=from@example.com
```

## 🎯 Phase 2 Preview

The next phase will focus on:
1. Building the spreadsheet grid UI with Livewire
2. Implementing formula parsing and calculation
3. Setting up real-time multiuser collaboration
4. Cell editing and formatting interface

## ❓ Troubleshooting

### Migrations not working
```bash
php artisan migrate:fresh  # Reset database
php artisan migrate        # Rerun all migrations
```

### Frontend not updating
```bash
npm run dev    # Ensure watcher is running in another terminal
```

### Permission denied errors
```bash
php artisan cache:clear
php artisan config:clear
php artisan auth:clear-resets
```

---

**Ready to begin Phase 2?** Check the TASK_LIST.md for next steps!
