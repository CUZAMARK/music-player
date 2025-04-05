# Music Player Web App

A full-stack music player with upload capabilities, playlist management, and advanced audio features built with PHP/MySQL and modern web technologies.

## Features

✅ **Core Features**
- Upload MP3 files with optional cover images and lyrics
- Drag-and-drop style file upload interface
- Visual waveform visualization during playback
- Shuffle and repeat modes
- Sleep timer functionality
- Lyrics display synchronized with playback
- Search and filter songs by title/artist

✅ **Advanced Features**
- Mobile-friendly responsive design
- Real-time audio visualization
- Playlist persistence with server-side storage
- Song duration display and progress bar
- Volume control with visual feedback
- Notifications for playback events

✅ **Design Features**
- Material Design icons integration
- Tailwind CSS powered UI
- Dark theme with vibrant accent colors
- Smooth animations and transitions

## Technologies Used

| Category       | Tools & Technologies Used                          |
|----------------|----------------------------------------------------|
| **Backend**    | PHP 8, MySQLi, MySQL Database                       |
| **Frontend**   | HTML5, CSS3 (Tailwind CSS), JavaScript ES6         |
| **Audio**      | Web Audio API, Canvas visualization                 |
| **UI/UX**      | Responsive design, Material Icons, Custom animations|
| **Storage**    | Server-side file storage for songs and covers      |

## Installation

1. **Prerequisites**
   - PHP 7.4+ (with mysqli extension)
   - MySQL 5.6+ database
   - Web server (Apache/Nginx recommended)
   - Directory permissions: `chmod -R 755 uploads/`

2. **Database Setup**
   ```sql
   CREATE DATABASE music_player;
   USE music_player;

   CREATE TABLE songs (
     id INT AUTO_INCREMENT PRIMARY KEY,
     title VARCHAR(255) NOT NULL,
     artist VARCHAR(100) DEFAULT 'Unknown',
     file VARCHAR(255) NOT NULL,
     cover VARCHAR(255) DEFAULT NULL,
     lyrics TEXT,
     uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
   );
