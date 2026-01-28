// Configuration - auto-detect if running locally
const API_BASE_URL = window.location.origin;
const CHECK_EXPIRATION_INTERVAL = 60000; // Check every 1 minute
const EXPIRATION_WARNING_TIME = 5 * 60 * 1000; // 5 minutes in milliseconds

// Global state
let map;
let markers = {};
let expirationCheckInterval;
let currentConfirmationSighting = null;

// Initialize map
function initMap() {
    // Center on a default location (will be updated based on sightings or user location)
    map = L.map('map').setView([37.7749, -122.4194], 10);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        maxZoom: 19
    }).addTo(map);

    // Request user's location immediately (only works on HTTPS or localhost)
    if (navigator.geolocation && (window.location.protocol === 'https:' || window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1')) {
        navigator.geolocation.getCurrentPosition(
            (position) => {
                // Success - center map on user's location
                const userLat = position.coords.latitude;
                const userLon = position.coords.longitude;
                map.setView([userLat, userLon], 13);
                
                // Add a marker for user's current location
                L.marker([userLat, userLon], {
                    icon: L.icon({
                        iconUrl: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIyNSIgaGVpZ2h0PSI0MSIgdmlld0JveD0iMCAwIDI1IDQxIj48cGF0aCBmaWxsPSIjMDA3YmZmIiBkPSJNMTIuNSAwQzUuNiAwIDAgNS42IDAgMTIuNWMwIDEuNCAwLjIgMi44IDAuNyA0LjFMMTIuNSA0MWw2LjgtMjQuNGMwLjQtMS4zIDAuNy0yLjcgMC43LTQuMUMyNSA1LjYgMTkuNCAwIDEyLjUgMHoiLz48Y2lyY2xlIGZpbGw9IiNmZmYiIGN4PSIxMi41IiBjeT0iMTIuNSIgcj0iNCIvPjwvc3ZnPg==',
                        iconSize: [25, 41],
                        iconAnchor: [12, 41],
                        popupAnchor: [1, -34]
                    })
                }).addTo(map).bindPopup('📍 Your Location').openPopup();
                
                console.log('User location:', userLat, userLon);
            },
            (error) => {
                // Error or denied - just log it
                console.warn('Location access denied or unavailable:', error.message);
            },
            {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 0
            }
        );
    } else if (window.location.protocol !== 'https:' && window.location.hostname !== 'localhost' && window.location.hostname !== '127.0.0.1') {
        console.info('Geolocation requires HTTPS or localhost. Access via http://localhost:8000/index.html for location features.');
    }
}

// Fetch and display sightings
async function loadSightings() {
    try {
        const response = await fetch(`${API_BASE_URL}/list_sightings.php?limit=500`);
        const data = await response.json();

        if (data.data && data.data.length > 0) {
            // Clear existing markers
            Object.values(markers).forEach(marker => map.removeLayer(marker));
            markers = {};

            // Add new markers
            data.data.forEach(sighting => {
                addSightingMarker(sighting);
            });

            // Center map on first sighting if we haven't moved yet
            if (data.data.length > 0 && !map._moved) {
                map.setView([data.data[0].lat, data.data[0].lon], 13);
            }
        }
    } catch (error) {
        console.error('Error loading sightings:', error);
    }
}

// Add a marker for a sighting
function addSightingMarker(sighting) {
    const marker = L.marker([sighting.lat, sighting.lon]).addTo(map);
    
    // Calculate time remaining
    let expiresText = '';
    if (sighting.expires_at) {
        const expiresAt = new Date(sighting.expires_at);
        const now = new Date();
        const msRemaining = expiresAt - now;
        const hoursRemaining = Math.floor(msRemaining / (1000 * 60 * 60));
        const minutesRemaining = Math.floor((msRemaining % (1000 * 60 * 60)) / (1000 * 60));
        
        if (msRemaining > 0) {
            expiresText = `<div class="popup-expires">⏰ Expires in ${hoursRemaining}h ${minutesRemaining}m</div>`;
        }
    }
    
    const takenAt = new Date(sighting.taken_at);
    const popupContent = `
        <div>
            <img src="${sighting.image_url}" class="popup-image" alt="Sighting" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22200%22 height=%22150%22><rect fill=%22%23ddd%22 width=%22200%22 height=%22150%22/><text x=%2250%%22 y=%2250%%22 fill=%22%23999%22 text-anchor=%22middle%22>No Image</text></svg>'">
            <div class="popup-info">
                <div class="popup-time">${takenAt.toLocaleString()}</div>
                <div>📍 ${sighting.lat.toFixed(6)}, ${sighting.lon.toFixed(6)}</div>
                ${expiresText}
            </div>
        </div>
    `;
    
    marker.bindPopup(popupContent);
    markers[sighting.id] = marker;
}

// Check for expiring sightings
async function checkExpirations() {
    try {
        const response = await fetch(`${API_BASE_URL}/check_expirations.php`);
        const data = await response.json();

        if (data.expiring_soon && data.expiring_soon.length > 0) {
            // Show confirmation dialog for expiring sightings
            data.expiring_soon.forEach(sighting => {
                const expiresAt = new Date(sighting.expires_at);
                const now = new Date();
                const msRemaining = expiresAt - now;

                // Only show confirmation if within warning time and not already expired
                if (msRemaining > 0 && msRemaining <= EXPIRATION_WARNING_TIME) {
                    showConfirmationDialog(sighting);
                }
            });
        }

        // Remove deleted markers
        if (data.deleted_ids && data.deleted_ids.length > 0) {
            data.deleted_ids.forEach(id => {
                if (markers[id]) {
                    map.removeLayer(markers[id]);
                    delete markers[id];
                }
            });
        }

        // Reload sightings to update the map
        if (data.deleted_ids && data.deleted_ids.length > 0) {
            await loadSightings();
        }
    } catch (error) {
        console.error('Error checking expirations:', error);
    }
}

// Show confirmation dialog
function showConfirmationDialog(sighting) {
    // Don't show if already showing one
    if (currentConfirmationSighting) {
        return;
    }

    currentConfirmationSighting = sighting;
    const modal = document.getElementById('confirmationModal');
    const details = document.getElementById('confirmationDetails');
    
    const expiresAt = new Date(sighting.expires_at);
    const now = new Date();
    const minutesRemaining = Math.floor((expiresAt - now) / (1000 * 60));
    
    details.innerHTML = `Expires in approximately ${minutesRemaining} minutes`;
    modal.style.display = 'block';
}

// Confirm sighting (keep it alive)
async function confirmSighting(sightingId) {
    try {
        const response = await fetch(`${API_BASE_URL}/confirm_sighting.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ sighting_id: sightingId })
        });

        const data = await response.json();
        
        if (data.success) {
            console.log('Sighting confirmed, expires at:', data.expires_at);
            await loadSightings(); // Reload to show updated expiration time
        }
    } catch (error) {
        console.error('Error confirming sighting:', error);
    }
}

// Upload sighting
async function uploadSighting(file) {
    const formData = new FormData();
    formData.append('image', file);

    const loadingEl = document.getElementById('uploadLoading');
    const errorEl = document.getElementById('uploadError');
    const successEl = document.getElementById('uploadSuccess');
    const submitBtn = document.getElementById('submitUpload');

    loadingEl.classList.add('active');
    errorEl.textContent = '';
    successEl.textContent = '';
    submitBtn.disabled = true;

    try {
        const response = await fetch(`${API_BASE_URL}/upload_sighting.php`, {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (response.ok && data.sighting_id) {
            successEl.textContent = '✓ Upload successful! Sighting added to map.';
            setTimeout(() => {
                closeUploadModal();
                loadSightings();
            }, 1500);
        } else {
            errorEl.textContent = data.error || 'Upload failed. Please try again.';
            submitBtn.disabled = false;
        }
    } catch (error) {
        console.error('Upload error:', error);
        errorEl.textContent = 'Network error. Please check your connection.';
        submitBtn.disabled = false;
    } finally {
        loadingEl.classList.remove('active');
    }
}

// Modal controls
function openUploadModal() {
    document.getElementById('uploadModal').style.display = 'block';
}

function closeUploadModal() {
    const modal = document.getElementById('uploadModal');
    modal.style.display = 'none';
    document.getElementById('imageFile').value = '';
    document.getElementById('previewContainer').innerHTML = '';
    document.getElementById('uploadError').textContent = '';
    document.getElementById('uploadSuccess').textContent = '';
    document.getElementById('submitUpload').disabled = true;
}

function closeConfirmationModal() {
    document.getElementById('confirmationModal').style.display = 'none';
    currentConfirmationSighting = null;
}

// Event listeners
document.addEventListener('DOMContentLoaded', () => {
    initMap();
    loadSightings();

    // Start expiration checking
    expirationCheckInterval = setInterval(checkExpirations, CHECK_EXPIRATION_INTERVAL);
    
    // Initial expiration check after 5 seconds
    setTimeout(checkExpirations, 5000);

    // Upload button
    document.getElementById('uploadBtn').addEventListener('click', openUploadModal);

    // Refresh button
    document.getElementById('refreshBtn').addEventListener('click', loadSightings);

    // Close modal
    document.querySelector('.close').addEventListener('click', closeUploadModal);
    document.getElementById('cancelUpload').addEventListener('click', closeUploadModal);

    // File selection
    document.getElementById('imageFile').addEventListener('change', (e) => {
        const file = e.target.files[0];
        const previewContainer = document.getElementById('previewContainer');
        const submitBtn = document.getElementById('submitUpload');

        if (file) {
            // Show preview
            const reader = new FileReader();
            reader.onload = (event) => {
                previewContainer.innerHTML = `<img src="${event.target.result}" class="preview-image" alt="Preview">`;
            };
            reader.readAsDataURL(file);
            submitBtn.disabled = false;
        } else {
            previewContainer.innerHTML = '';
            submitBtn.disabled = true;
        }
    });

    // Submit upload
    document.getElementById('submitUpload').addEventListener('click', () => {
        const fileInput = document.getElementById('imageFile');
        const file = fileInput.files[0];
        if (file) {
            uploadSighting(file);
        }
    });

    // Confirmation dialog buttons
    document.getElementById('confirmYes').addEventListener('click', () => {
        if (currentConfirmationSighting) {
            confirmSighting(currentConfirmationSighting.id);
        }
        closeConfirmationModal();
    });

    document.getElementById('confirmNo').addEventListener('click', () => {
        // Just close - the backend will delete it on next check
        closeConfirmationModal();
    });

    // Close modals when clicking outside
    window.addEventListener('click', (e) => {
        const uploadModal = document.getElementById('uploadModal');
        const confirmModal = document.getElementById('confirmationModal');
        
        if (e.target === uploadModal) {
            closeUploadModal();
        }
        if (e.target === confirmModal) {
            closeConfirmationModal();
        }
    });
});

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (expirationCheckInterval) {
        clearInterval(expirationCheckInterval);
    }
});
