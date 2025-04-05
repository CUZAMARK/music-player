<?php
// index.php
// Database configuration
$host = "localhost";
$db   = "music_player";
$user = "root";
$pass = "";
// Create connection using MySQLi
$conn = new mysqli($host, $user, $pass, $db);
if($conn->connect_error){
  die("Connection failed: " . $conn->connect_error);
}
// API endpoints if "action" parameter is provided
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    if ($action === 'getPlaylist') {
        header("Content-Type: application/json");
        $sql = "SELECT * FROM songs ORDER BY uploaded_at DESC";
        $result = $conn->query($sql);
        $songs = array();
        while ($row = $result->fetch_assoc()) {
            $songs[] = $row;
        }
        echo json_encode($songs);
        $conn->close();
        exit;
    }
    if ($action === 'uploadSong') {
        header("Content-Type: application/json");
        // Process the song file upload
        if (isset($_FILES['song'])) {
            $uploadDir = 'uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $songName = basename($_FILES['song']['name']);
            $targetSong = $uploadDir . $songName;
            $songType = strtolower(pathinfo($targetSong, PATHINFO_EXTENSION));
            // Allow only MP3 files
            if ($songType !== "mp3") {
                echo json_encode(array("error" => "Only MP3 files are allowed for the song."));
                exit;
            }
            if (move_uploaded_file($_FILES['song']['tmp_name'], $targetSong)) {
                $songURL = $targetSong;
            } else {
                echo json_encode(array("error" => "Error uploading the song file."));
                exit;
            }
        } else {
            echo json_encode(array("error" => "No song file received."));
            exit;
        }
        // Process optional cover image upload
        $coverURL = '';
        if (isset($_FILES['cover']) && $_FILES['cover']['error'] == UPLOAD_ERR_OK) {
            $coverName = basename($_FILES['cover']['name']);
            $targetCover = $uploadDir . $coverName;
            $coverType = strtolower(pathinfo($targetCover, PATHINFO_EXTENSION));
            // Allow only specific image types
            $allowed = array("jpg", "jpeg", "png", "gif");
            if (!in_array($coverType, $allowed)) {
                echo json_encode(array("error" => "Cover image must be jpg, jpeg, png, or gif."));
                exit;
            }
            if (move_uploaded_file($_FILES['cover']['tmp_name'], $targetCover)) {
                $coverURL = $targetCover;
            }
        }
        // Get additional data from the POST request
        $title  = $conn->real_escape_string($_POST['title'] ?? $songName);
        $artist = $conn->real_escape_string($_POST['artist'] ?? 'Uploaded Artist');
        $lyrics = $conn->real_escape_string($_POST['lyrics'] ?? '');
        $stmt = $conn->prepare("INSERT INTO songs (title, file, cover, artist, lyrics) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $title, $songURL, $coverURL, $artist, $lyrics);
        if ($stmt->execute()){
            echo json_encode(array(
                "success" => "File uploaded successfully",
                "song" => array(
                    "id"     => $stmt->insert_id,
                    "title"  => $title,
                    "file"   => $songURL,
                    "cover"  => $coverURL,
                    "artist" => $artist,
                    "lyrics" => $lyrics
                )
            ));
        } else {
            echo json_encode(array("error" => "Database error: " . $conn->error));
        }
        $stmt->close();
        $conn->close();
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Music Player</title>
  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- Material Icons -->
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
  <!-- External CSS file -->
  <link rel="stylesheet" href="style.css">
  <style>
    .current-song { background: #374151; }
    .toast {
      position: fixed;
      bottom: 20px;
      left: 50%;
      transform: translateX(-50%);
      background: #1f2937;
      color: white;
      padding: 12px 24px;
      border-radius: 8px;
      display: none;
      z-index: 1000;
    }
    .toast.show { display: block; }
    .progress-filled, .volume-filled {
      transition: all 0.1s linear;
    }
    .visualizer {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      pointer-events: none;
    }
    /* New styles for song covers */
    .playlist-item img {
      object-fit: cover;
      border-radius: 4px;
    }
  </style>
</head>
<body class="bg-gray-900 text-white min-h-screen">
  <!-- Main container -->
  <div class="container mx-auto px-4 py-8">
    <div class="flex flex-col lg:flex-row gap-8">
      <!-- Playlist section -->
      <div class="w-full lg:w-1/3 bg-gray-800 rounded-xl p-6 shadow-lg">
        <div class="flex justify-between items-center mb-6">
          <h2 class="text-2xl font-bold">Playlist</h2>
          <div class="flex gap-2">
            <button id="refreshBtn" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg flex items-center gap-2 transition">
              <i class="material-icons">refresh</i>
              <span>Refresh</span>
            </button>
            <button id="uploadBtn" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center gap-2 transition">
              <i class="material-icons">upload</i>
              <span>Upload</span>
            </button>
          </div>
        </div>
        <div class="mb-4">
          <input type="text" id="searchInput" placeholder="Search songs..." class="w-full bg-gray-700 text-white px-4 py-2 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>
        <div id="playlist" class="max-h-[500px] overflow-y-auto">
          <!-- Playlist items will be added here -->
        </div>
        <div id="emptyState" class="text-center py-12 text-gray-400">
          <i class="material-icons text-5xl mb-4">music_off</i>
          <p class="text-lg mb-4">Your playlist is empty</p>
          <button id="uploadBtnEmpty" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg flex items-center gap-2 mx-auto transition">
            <i class="material-icons">upload</i>
            <span>Upload MP3 files</span>
          </button>
        </div>
      </div>
      <!-- Player section -->
      <div class="w-full lg:w-2/3">
        <div class="player-container rounded-xl p-6 relative overflow-hidden">
          <canvas class="visualizer" id="visualizer"></canvas>
          <div class="flex flex-col md:flex-row gap-6 items-center">
            <!-- Album art -->
            <div class="album-art w-48 h-48 rounded-lg flex items-center justify-center">
              <i class="material-icons text-6xl text-gray-500" id="albumArtIcon">music_note</i>
              <img id="albumArtImage" src="" alt="Album Art" class="w-full h-full object-cover rounded-lg hidden">
            </div>
            <!-- Song info -->
            <div class="flex-1">
              <h1 class="text-2xl font-bold truncate" id="songTitle">No song selected</h1>
              <p class="text-gray-400 mb-4" id="songArtist">Unknown artist</p>
              <!-- Progress bar -->
              <div class="mb-2">
                <div class="progress-bar rounded-full w-full">
                  <div class="progress-filled rounded-full" id="progressBar" style="width: 0%"></div>
                </div>
              </div>
              <div class="flex justify-between text-sm text-gray-400 mb-6">
                <span id="currentTime">0:00</span>
                <span id="durationTime">0:00</span>
              </div>
              <!-- Controls -->
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                  <button id="shuffleBtn" class="control-btn" title="Shuffle">
                    <i class="material-icons">shuffle</i>
                  </button>
                  <button id="prevBtn" class="control-btn" title="Previous">
                    <i class="material-icons">skip_previous</i>
                  </button>
                </div>
                <button id="playBtn" class="control-btn bg-blue-600 hover:bg-blue-700 w-14 h-14" title="Play/Pause">
                  <i class="material-icons text-2xl" id="playIcon">play_arrow</i>
                  <i class="material-icons text-2xl hidden" id="pauseIcon">pause</i>
                </button>
                <div class="flex items-center gap-2">
                  <button id="nextBtn" class="control-btn" title="Next">
                    <i class="material-icons">skip_next</i>
                  </button>
                  <button id="repeatBtn" class="control-btn" title="Repeat">
                    <i class="material-icons">repeat</i>
                  </button>
                </div>
              </div>
            </div>
          </div>
          <!-- Additional controls -->
          <div class="flex justify-between items-center mt-6">
            <div class="flex items-center gap-4">
              <div class="relative">
                <button id="volumeBtn" class="control-btn" title="Volume">
                  <i class="material-icons" id="volumeIcon">volume_up</i>
                </button>
                <div id="volumeControl" class="absolute bottom-full left-1/2 transform -translate-x-1/2 bg-gray-800 p-3 rounded-lg shadow-lg mb-2 hidden">
                  <div class="volume-bar rounded-full">
                    <div class="volume-filled rounded-full" id="volumeBar"></div>
                  </div>
                </div>
              </div>
              <div class="relative">
                <button id="timerBtn" class="control-btn" title="Sleep Timer">
                  <i class="material-icons">timer</i>
                </button>
                <div id="timerContainer" class="absolute bottom-full left-0 bg-gray-800 p-3 rounded-lg shadow-lg mb-2 w-40 hidden">
                  <div class="text-sm mb-2">Sleep Timer</div>
                  <div class="flex flex-col gap-1">
                    <div class="timer-option" data-minutes="0">Off</div>
                    <div class="timer-option" data-minutes="5">5 minutes</div>
                    <div class="timer-option" data-minutes="15">15 minutes</div>
                    <div class="timer-option" data-minutes="30">30 minutes</div>
                    <div class="timer-option" data-minutes="60">1 hour</div>
                  </div>
                </div>
              </div>
            </div>
            <div class="eq-bars" id="eqBars">
              <div class="eq-bar"></div>
              <div class="eq-bar"></div>
              <div class="eq-bar"></div>
            </div>
          </div>
        </div>
        <!-- Lyrics section -->
        <div class="mt-6 bg-gray-800 rounded-xl p-6">
          <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold">Lyrics</h3>
          </div>
          <div id="lyricsContent" class="text-center text-gray-400">
            No lyrics available for the current song
          </div>
        </div>
      </div>
    </div>
  </div>
  <!-- Toast notification -->
  <div class="toast" id="toast"></div>
  <!-- Upload form modal -->
  <div id="uploadModal" class="fixed inset-0 flex items-center justify-center bg-black bg-opacity-50 hidden">
    <div class="bg-gray-800 rounded-xl p-6 w-11/12 md:w-1/2 relative">
      <h2 class="text-2xl mb-4">Upload New Song</h2>
      <form id="uploadForm">
        <div class="mb-4">
          <label class="block mb-1">Song Title</label>
          <input type="text" id="songTitleInput" class="w-full p-2 rounded bg-gray-700" placeholder="Enter song title">
        </div>
        <div class="mb-4">
          <label class="block mb-1">Artist</label>
          <input type="text" id="songArtistInput" class="w-full p-2 rounded bg-gray-700" placeholder="Enter artist name">
        </div>
        <div class="mb-4">
          <label class="block mb-1">Cover Image (Optional)</label>
          <input type="file" id="coverFileInput" accept="image/*" class="w-full p-2 rounded bg-gray-700">
        </div>
        <div class="mb-4">
          <label class="block mb-1">Lyrics</label>
          <textarea id="songLyricsInput" class="w-full p-2 rounded bg-gray-700" placeholder="Enter lyrics"></textarea>
        </div>
        <div class="mb-4">
          <label class="block mb-1">Song File (MP3)</label>
          <input type="file" id="songFileInput" accept=".mp3,audio/mp3" class="w-full p-2 rounded bg-gray-700">
        </div>
        <div class="flex justify-end gap-4">
          <button type="button" id="cancelUploadBtn" class="bg-gray-700 hover:bg-gray-600 px-4 py-2 rounded">Cancel</button>
          <button type="submit" id="submitUploadBtn" class="bg-blue-600 hover:bg-blue-700 px-4 py-2 rounded">Upload</button>
        </div>
      </form>
    </div>
  </div>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Audio player state and DOM elements
      const state = {
        playlist: [],
        filteredPlaylist: [],
        currentIndex: -1,
        isPlaying: false,
        isShuffled: false,
        isRepeating: false,
        volume: 0.7,
        timer: null,
        searchQuery: '',
        audioContext: null,
        analyser: null,
        dataArray: null,
        animationId: null
      };
      const elements = {
        audio: new Audio(),
        playBtn: document.getElementById('playBtn'),
        playIcon: document.getElementById('playIcon'),
        pauseIcon: document.getElementById('pauseIcon'),
        prevBtn: document.getElementById('prevBtn'),
        nextBtn: document.getElementById('nextBtn'),
        shuffleBtn: document.getElementById('shuffleBtn'),
        repeatBtn: document.getElementById('repeatBtn'),
        volumeBtn: document.getElementById('volumeBtn'),
        volumeIcon: document.getElementById('volumeIcon'),
        volumeControl: document.getElementById('volumeControl'),
        volumeBar: document.getElementById('volumeBar'),
        timerBtn: document.getElementById('timerBtn'),
        timerContainer: document.getElementById('timerContainer'),
        progressBar: document.getElementById('progressBar'),
        currentTime: document.getElementById('currentTime'),
        durationTime: document.getElementById('durationTime'),
        songTitle: document.getElementById('songTitle'),
        songArtist: document.getElementById('songArtist'),
        albumArtIcon: document.getElementById('albumArtIcon'),
        albumArtImage: document.getElementById('albumArtImage'),
        playlist: document.getElementById('playlist'),
        emptyState: document.getElementById('emptyState'),
        uploadBtn: document.getElementById('uploadBtn'),
        uploadBtnEmpty: document.getElementById('uploadBtnEmpty'),
        refreshBtn: document.getElementById('refreshBtn'),
        searchInput: document.getElementById('searchInput'),
        lyricsContent: document.getElementById('lyricsContent'),
        eqBars: document.getElementById('eqBars'),
        visualizer: document.getElementById('visualizer'),
        toast: document.getElementById('toast'),
        uploadModal: document.getElementById('uploadModal'),
        uploadForm: document.getElementById('uploadForm'),
        cancelUploadBtn: document.getElementById('cancelUploadBtn'),
        submitUploadBtn: document.getElementById('submitUploadBtn'),
        songTitleInput: document.getElementById('songTitleInput'),
        songArtistInput: document.getElementById('songArtistInput'),
        songLyricsInput: document.getElementById('songLyricsInput'),
        songFileInput: document.getElementById('songFileInput'),
        coverFileInput: document.getElementById('coverFileInput')
      };
      // Initialize the player
      function init() {
        elements.audio.volume = state.volume;
        elements.volumeBar.style.height = `${state.volume * 100}%`;
        loadPlaylist();
        setupEventListeners();
        // Initialize audio context on first user interaction
        document.addEventListener('click', function initAudio() {
          try {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            state.audioContext = new AudioContext();
            state.analyser = state.audioContext.createAnalyser();
            state.analyser.fftSize = 256;
            // Connect audio nodes when playback starts
            elements.audio.addEventListener('play', () => {
              const source = state.audioContext.createMediaElementSource(elements.audio);
              source.connect(state.analyser);
              state.analyser.connect(state.audioContext.destination);
              state.dataArray = new Uint8Array(state.analyser.frequencyBinCount);
              visualize();
            }, { once: true });
            document.removeEventListener('click', initAudio);
          } catch (e) {
            console.error('Audio context initialization failed:', e);
          }
        }, { once: true });
      }
      // Fetch playlist via AJAX from the PHP endpoint
      function loadPlaylist() {
        fetch('index.php?action=getPlaylist')
          .then(response => response.json())
          .then(data => {
            if (Array.isArray(data)) {
              state.playlist = data;
              state.filteredPlaylist = [...data];
              updatePlaylistUI();
              elements.emptyState.style.display = data.length > 0 ? 'none' : 'block';
              if(data.length > 0 && state.currentIndex === -1) {
                state.currentIndex = 0;
              }
            } else {
              console.error("Error fetching playlist:", data.error);
            }
          })
          .catch(error => console.error("Fetch error:", error));
      }
      // Update the playlist UI
      function updatePlaylistUI() {
        elements.playlist.innerHTML = '';
        if (state.filteredPlaylist.length === 0) {
          const item = document.createElement('div');
          item.className = 'text-center py-4 text-gray-400';
          item.textContent = 'No songs found';
          elements.playlist.appendChild(item);
          return;
        }
        state.filteredPlaylist.forEach((song, index) => {
          const item = document.createElement('div');
          item.className = `flex items-center justify-between p-3 rounded-lg cursor-pointer playlist-item ${state.currentIndex === index ? 'current-song' : ''}`;
          item.dataset.index = index;
          const coverUrl = song.cover ? song.cover : '/default-cover.jpg'; // Fallback cover
          item.innerHTML = `
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 bg-gray-700 rounded overflow-hidden">
                ${coverUrl ? `<img src="${coverUrl}" alt="Cover" class="w-full h-full object-cover rounded">` : `
                <i class="material-icons text-lg">music_note</i>
                `}
              </div>
              <div>
                <div class="font-medium">${song.title}</div>
                <div class="text-sm text-gray-400">${song.artist}</div>
              </div>
            </div>
            <div class="text-gray-400 text-sm">${formatTime(song.duration || 0)}</div>
          `;
          item.addEventListener('click', () => playSong(index));
          elements.playlist.appendChild(item);
        });
      }
      // Play song by index with critical fixes
      function playSong(index) {
        if (index < 0 || index >= state.playlist.length) return;
        state.currentIndex = index;
        const song = state.playlist[index];
        // Encode file path properly
        const filePath = encodeURI(song.file);
        // Update audio source correctly
        elements.audio.pause();
        elements.audio.src = filePath;
        elements.audio.load();
        // Handle browser autoplay restrictions
        const playPromise = elements.audio.play();
        if (playPromise !== undefined) {
          playPromise.then(() => {
            state.isPlaying = true;
            updatePlayButton();
            updatePlaylistUI();
            // Handle suspended audio context
            if (state.audioContext?.state === 'suspended') {
              state.audioContext.resume();
            }
            // Show mobile notification
            showSongNotification(song);
          }).catch(error => {
            showNotification('Click anywhere to start playback');
            document.body.addEventListener('click', function firstClick() {
              elements.audio.play();
              document.body.removeEventListener('click', firstClick);
            }, { once: true });
          });
        }
        // Update UI elements
        elements.songTitle.textContent = song.title;
        elements.songArtist.textContent = song.artist;
        if (song.cover) {
          elements.albumArtIcon.style.display = 'none';
          elements.albumArtImage.src = song.cover;
          elements.albumArtImage.style.display = 'block';
        } else {
          elements.albumArtIcon.style.display = 'block';
          elements.albumArtImage.style.display = 'none';
        }
        if (song.lyrics) {
          elements.lyricsContent.innerHTML = song.lyrics.split('\n').map(line =>
            `<div class="mb-2">${line}</div>`
          ).join('');
        } else {
          elements.lyricsContent.innerHTML = 'No lyrics available for this song';
        }
      }
      // Show mobile notifications
      function showSongNotification(song) {
        if ('Notification' in window) {
          Notification.requestPermission().then(permission => {
            if (permission === 'granted') {
              const coverUrl = song.cover || '/default-cover.jpg';
              const notification = new Notification(song.title, {
                body: `Artist: ${song.artist}`,
                icon: coverUrl,
                tag: 'music-player-notification'
              });
              notification.onclick = () => {
                window.focus();
                notification.close();
              };
            }
          });
        }
      }
      // Update play/pause button display
      function updatePlayButton() {
        if (state.isPlaying) {
          elements.playIcon.classList.add('hidden');
          elements.pauseIcon.classList.remove('hidden');
        } else {
          elements.playIcon.classList.remove('hidden');
          elements.pauseIcon.classList.add('hidden');
        }
      }
      // Setup event listeners
      function setupEventListeners() {
        elements.playBtn.addEventListener('click', () => {
          if (state.currentIndex === -1 && state.playlist.length > 0) {
            playSong(0);
          } else if (state.isPlaying) {
            elements.audio.pause();
            state.isPlaying = false;
            updatePlayButton();
          } else {
            elements.audio.play().then(() => {
              state.isPlaying = true;
              updatePlayButton();
            });
          }
        });
        elements.prevBtn.addEventListener('click', () => {
          if (state.currentIndex > 0) {
            playSong(state.currentIndex - 1);
          } else {
            playSong(state.playlist.length - 1);
          }
        });
        elements.nextBtn.addEventListener('click', () => {
          if (state.currentIndex < state.playlist.length - 1) {
            playSong(state.currentIndex + 1);
          } else {
            playSong(0);
          }
        });
        elements.shuffleBtn.addEventListener('click', () => {
          state.isShuffled = !state.isShuffled;
          elements.shuffleBtn.classList.toggle('active-btn', state.isShuffled);
          showNotification(state.isShuffled ? 'Shuffle enabled' : 'Shuffle disabled');
        });
        elements.repeatBtn.addEventListener('click', () => {
          state.isRepeating = !state.isRepeating;
          elements.repeatBtn.classList.toggle('active-btn', state.isRepeating);
          showNotification(state.isRepeating ? 'Repeat enabled' : 'Repeat disabled');
        });
        elements.volumeBtn.addEventListener('click', () => {
          elements.volumeControl.classList.toggle('hidden');
        });
        elements.volumeBar.addEventListener('click', (e) => {
          const rect = e.target.getBoundingClientRect();
          const volume = 1 - (e.clientY - rect.top) / rect.height;
          setVolume(Math.max(0, Math.min(1, volume)));
        });
        elements.timerBtn.addEventListener('click', () => {
          elements.timerContainer.classList.toggle('hidden');
        });
        document.querySelectorAll('.timer-option').forEach(option => {
          option.addEventListener('click', (e) => {
            const minutes = parseInt(e.target.dataset.minutes);
            setTimer(minutes);
          });
        });
        elements.searchInput.addEventListener('input', (e) => {
          state.searchQuery = e.target.value.toLowerCase();
          filterPlaylist();
        });
        elements.uploadBtn.addEventListener('click', showUploadModal);
        elements.uploadBtnEmpty.addEventListener('click', showUploadModal);
        elements.cancelUploadBtn.addEventListener('click', hideUploadModal);
        elements.uploadForm.addEventListener('submit', handleUploadSubmit);
        elements.refreshBtn.addEventListener('click', () => {
          showNotification('Playlist refreshed');
          loadPlaylist();
        });
        elements.audio.addEventListener('timeupdate', updateProgress);
        elements.audio.addEventListener('ended', handleSongEnd);
        elements.audio.addEventListener('durationchange', updateDuration);
        elements.audio.addEventListener('volumechange', updateVolumeIcon);
      }
      function filterPlaylist() {
        if (!state.searchQuery) {
          state.filteredPlaylist = [...state.playlist];
        } else {
          state.filteredPlaylist = state.playlist.filter(song =>
            song.title.toLowerCase().includes(state.searchQuery) ||
            song.artist.toLowerCase().includes(state.searchQuery)
          );
        }
        updatePlaylistUI();
      }
      function updateProgress() {
        const currentTime = elements.audio.currentTime;
        const duration = elements.audio.duration || 1;
        const progressPercent = (currentTime / duration) * 100;
        elements.progressBar.style.width = `${progressPercent}%`;
        elements.currentTime.textContent = formatTime(currentTime);
      }
      function updateDuration() {
        elements.durationTime.textContent = formatTime(elements.audio.duration || 0);
      }
      function formatTime(seconds) {
        const mins = Math.floor(seconds / 60);
        const secs = Math.floor(seconds % 60);
        return `${mins}:${secs < 10 ? '0' : ''}${secs}`;
      }
      function handleSongEnd() {
        if (state.isRepeating) {
          elements.audio.currentTime = 0;
          elements.audio.play();
        } else if (state.isShuffled) {
          const nextIndex = Math.floor(Math.random() * state.playlist.length);
          playSong(nextIndex);
        } else {
          if (state.currentIndex < state.playlist.length - 1) {
            playSong(state.currentIndex + 1);
          } else {
            state.isPlaying = false;
            updatePlayButton();
          }
        }
      }
      function setVolume(volume) {
        state.volume = volume;
        elements.audio.volume = volume;
        elements.volumeBar.style.height = `${volume * 100}%`;
        elements.volumeControl.classList.add('hidden');
      }
      function updateVolumeIcon() {
        if (elements.audio.muted || elements.audio.volume === 0) {
          elements.volumeIcon.textContent = 'volume_off';
        } else if (elements.audio.volume < 0.5) {
          elements.volumeIcon.textContent = 'volume_down';
        } else {
          elements.volumeIcon.textContent = 'volume_up';
        }
      }
      function setTimer(minutes) {
        if (state.timer) {
          clearTimeout(state.timer);
          state.timer = null;
        }
        if (minutes > 0) {
          state.timer = setTimeout(() => {
            elements.audio.pause();
            state.isPlaying = false;
            updatePlayButton();
            showNotification('Sleep timer stopped playback');
          }, minutes * 60 * 1000);
          document.querySelectorAll('.timer-option').forEach(option => {
            option.classList.toggle('active', parseInt(option.dataset.minutes) === minutes);
          });
          showNotification(`Sleep timer set for ${minutes} minutes`);
        } else {
          document.querySelectorAll('.timer-option').forEach(option => {
            option.classList.remove('active');
          });
          showNotification('Sleep timer disabled');
        }
        elements.timerContainer.classList.add('hidden');
      }
      function showNotification(message, isError = false) {
        elements.toast.textContent = message;
        elements.toast.className = `toast ${isError ? 'error' : ''} show`;
        setTimeout(() => {
          elements.toast.className = 'toast';
        }, 3000);
      }
      function showUploadModal() {
        elements.uploadForm.reset();
        elements.uploadModal.classList.remove('hidden');
      }
      function hideUploadModal() {
        elements.uploadModal.classList.add('hidden');
      }
      function handleUploadSubmit(event) {
        event.preventDefault();
        if (elements.songFileInput.files.length === 0) {
          showNotification("No song file selected", true);
          return;
        }
        const formData = new FormData();
        formData.append('song', elements.songFileInput.files[0]);
        if (elements.coverFileInput.files.length > 0) {
          formData.append('cover', elements.coverFileInput.files[0]);
        }
        formData.append('title', elements.songTitleInput.value || elements.songFileInput.files[0].name.replace('.mp3', ''));
        formData.append('artist', elements.songArtistInput.value || 'Uploaded Artist');
        formData.append('lyrics', elements.songLyricsInput.value || '');
        showNotification("Uploading...");
        fetch('index.php?action=uploadSong', {
          method: 'POST',
          body: formData
        })
        .then(response => response.json())
        .then(result => {
          if (result.success) {
            showNotification(result.success);
            loadPlaylist();
            elements.emptyState.style.display = 'none';
            hideUploadModal();
          } else {
            showNotification(result.error, true);
          }
        })
        .catch(error => {
          console.error("Upload error:", error);
          showNotification("Upload failed", true);
        });
      }
      function visualize() {
        if (!state.analyser) return;
        state.analyser.getByteFrequencyData(state.dataArray);
        const canvas = elements.visualizer;
        const ctx = canvas.getContext('2d');
        const width = canvas.width = canvas.offsetWidth;
        const height = canvas.height = canvas.offsetHeight;
        ctx.clearRect(0, 0, width, height);
        const barWidth = (width / state.dataArray.length) * 2.5;
        let x = 0;
        for (let i = 0; i < state.dataArray.length; i++) {
          const barHeight = (state.dataArray[i] / 255) * height;
          ctx.fillStyle = `rgb(${50 + state.dataArray[i]}, 100, 200)`;
          ctx.fillRect(x, height - barHeight, barWidth, barHeight);
          x += barWidth + 1;
        }
        state.animationId = requestAnimationFrame(visualize);
      }
      // Start the player
      init();
    });
  </script>
</body>
</html>
