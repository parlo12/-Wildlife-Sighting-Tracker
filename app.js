// Configuration - auto-detect if running locally
const API_BASE_URL = window.location.origin;
const CHECK_EXPIRATION_INTERVAL = 60000; // Check every 1 minute
const EXPIRATION_WARNING_TIME = 5 * 60 * 1000; // 5 minutes in milliseconds
const VAPID_PUBLIC_KEY = 'BOuypGn5hkWyyCVpEO7f5yKxkg-fi-6Fum1YARnCCzpN5fohYzjSsrEDoL_W44qGsSMLfrvuBj5u6M754FYzdaQ';

// Translations
const translations = {
    ht: {
        reportBtn: '➕ Ripòte Glas',
        refreshBtn: '🔄 Rafrechi Kat',
        modalHeader: 'Ripòte locasyon glas',
        speciesLabel: 'Kisa ou te wè? *',
        speciesPlaceholder: 'ekri sa ou we a egzamp eske ou we glas nan zon nan',
        useLocationBtn: '📍 Sèvi ak lokasyon ou',
        cancelBtn: 'Anile',
        uploadBtn: 'Voye',
        uploading: 'Ap voye...',
        uploadSuccess: '✓ Voye avèk siksè! Rapò ajoute sou kat la.',
        uploadFailed: 'Voye echwe. Tanpri eseye ankò.',
        networkError: 'Erè rezo. Tanpri tcheke koneksyon ou.',
        locationError: 'Pa kapab jwenn lokasyon ou. Tanpri aktive sèvis lokasyon.',
        geoNotSupported: 'Navigatè ou a pa sipòte jeolokalizasyon.',
        gettingLocation: '📍 Ap chèche lokasyon...',
        locationAcquired: '✓ Lokasyon jwenn:',
        yourLocation: '📍 Lokasyon ou',
        expiringTitle: '⏰ Rapò ap ekspire byento',
        expiringMessage: 'Èske rapò sa a toujou nan zòn nan?',
        expiresIn: 'Ap ekspire nan anviwon',
        minutes: 'minit',
        confirmYes: 'Wi, Kenbe Li',
        confirmNo: 'Non, Retire Li',
        expiresInPopup: '⏰ Ekspire nan',
        notifTitle: 'Resevwa notifikasyon',
        notifBody: 'Aksepte pou resevwa notifikasyon lè nouvo glas yo poste nan zòn ou!',
        notifAccept: 'Aksepte',
        notifDeny: 'Non Mèsi',
    },
    es: {
        reportBtn: '➕ Reportar Vidrio',
        refreshBtn: '🔄 Actualizar Mapa',
        modalHeader: 'Reportar ubicación de vidrio',
        speciesLabel: '¿Qué viste? *',
        speciesPlaceholder: 'Escribe lo que viste, por ejemplo si viste vidrio en la zona',
        useLocationBtn: '📍 Usar mi ubicación',
        cancelBtn: 'Cancelar',
        uploadBtn: 'Enviar',
        uploading: 'Enviando...',
        uploadSuccess: '✓ ¡Enviado con éxito! Reporte agregado al mapa.',
        uploadFailed: 'Error al enviar. Por favor intenta de nuevo.',
        networkError: 'Error de red. Por favor verifica tu conexión.',
        locationError: 'No se pudo obtener la ubicación. Activa los servicios de ubicación.',
        geoNotSupported: 'Tu navegador no soporta geolocalización.',
        gettingLocation: '📍 Obteniendo ubicación...',
        locationAcquired: '✓ Ubicación obtenida:',
        yourLocation: '📍 Tu ubicación',
        expiringTitle: '⏰ Reporte por expirar',
        expiringMessage: '¿Este reporte sigue en el área?',
        expiresIn: 'Expira en aproximadamente',
        minutes: 'minutos',
        confirmYes: 'Sí, Mantener',
        confirmNo: 'No, Eliminar',
        expiresInPopup: '⏰ Expira en',
        notifTitle: 'Recibir notificaciones',
        notifBody: '¡Acepta para recibir notificaciones cuando se reporten nuevos vidrios en tu zona!',
        notifAccept: 'Aceptar',
        notifDeny: 'No Gracias',
    }
};

let currentLang = localStorage.getItem('glas_language') || 'ht';

function t(key) {
    return (translations[currentLang] && translations[currentLang][key]) || translations.ht[key] || key;
}

function setLanguage(lang) {
    currentLang = lang;
    localStorage.setItem('glas_language', lang);

    // Update static HTML elements with data-i18n
    document.querySelectorAll('[data-i18n]').forEach(el => {
        el.textContent = t(el.dataset.i18n);
    });
    document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
        el.placeholder = t(el.dataset.i18nPlaceholder);
    });

    // Update the language toggle button
    const langBtn = document.getElementById('langBtn');
    if (langBtn) {
        langBtn.textContent = '🌐 ' + lang.toUpperCase();
    }
}

// Global state
let map;
let markers = {};
let expirationCheckInterval;
let currentConfirmationSighting = null;
let currentLocation = null;
let userId = null;

// Generate or retrieve user ID
function getUserId() {
    if (!userId) {
        // Check if user ID exists in localStorage
        userId = localStorage.getItem('wildlife_user_id');

        if (!userId) {
            // Generate new unique user ID
            userId = 'user_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            localStorage.setItem('wildlife_user_id', userId);
            console.log('Generated new user ID:', userId);
        } else {
            console.log('Using existing user ID:', userId);
        }
    }
    return userId;
}

// Generate or retrieve visitor ID (for analytics)
function getVisitorId() {
    let visitorId = localStorage.getItem('glas_visitor_id');
    if (!visitorId) {
        visitorId = 'v_' + Date.now() + '_' + Math.random().toString(36).substr(2, 12);
        localStorage.setItem('glas_visitor_id', visitorId);
    }
    return visitorId;
}

// Generate session ID (new each browser session)
function getSessionId() {
    let sessionId = sessionStorage.getItem('glas_session_id');
    if (!sessionId) {
        sessionId = 's_' + Date.now() + '_' + Math.random().toString(36).substr(2, 8);
        sessionStorage.setItem('glas_session_id', sessionId);
    }
    return sessionId;
}

// Track visitor
async function trackVisit() {
    try {
        const visitorId = getVisitorId();
        const sessionId = getSessionId();

        // Detect if mobile
        const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);

        const visitData = {
            visitor_id: visitorId,
            session_id: sessionId,
            screen_width: window.screen.width,
            screen_height: window.screen.height,
            language: navigator.language || navigator.userLanguage,
            platform: navigator.platform,
            referrer: document.referrer,
            is_mobile: isMobile,
            page_url: window.location.pathname
        };

        await fetch(`${API_BASE_URL}/track_visit.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(visitData)
        });

        console.log('Visit tracked:', visitorId);
    } catch (error) {
        // Silent fail - don't disrupt user experience
        console.log('Visit tracking skipped');
    }
}

// Convert base64 to Uint8Array for VAPID key
function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding)
        .replace(/\-/g, '+')
        .replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

// Check if push notifications are supported and not yet asked
function canAskForNotificationPermission() {
    return 'Notification' in window &&
           'serviceWorker' in navigator &&
           'PushManager' in window &&
           Notification.permission === 'default' &&
           !localStorage.getItem('push_notification_asked');
}

// Subscribe to push notifications
async function subscribeToPushNotifications() {
    try {
        const registration = await navigator.serviceWorker.ready;

        const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY)
        });

        // Send subscription to server
        const response = await fetch(`${API_BASE_URL}/subscribe_push.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                endpoint: subscription.endpoint,
                p256dh: btoa(String.fromCharCode.apply(null, new Uint8Array(subscription.getKey('p256dh')))),
                auth: btoa(String.fromCharCode.apply(null, new Uint8Array(subscription.getKey('auth'))))
            })
        });

        const data = await response.json();
        console.log('Push subscription saved:', data);
        return true;
    } catch (error) {
        console.error('Error subscribing to push:', error);
        return false;
    }
}

// Show notification permission prompt
function showNotificationPrompt() {
    if (!canAskForNotificationPermission()) {
        return;
    }

    // Create the prompt UI
    const prompt = document.createElement('div');
    prompt.id = 'notificationPrompt';
    prompt.innerHTML = `
        <div style="
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 25px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            z-index: 10000;
            max-width: 90%;
            width: 400px;
            text-align: center;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        ">
            <div style="font-size: 24px; margin-bottom: 10px;">🔔</div>
            <div style="font-size: 16px; font-weight: 600; margin-bottom: 8px;">${t('notifTitle')}</div>
            <div style="font-size: 14px; opacity: 0.9; margin-bottom: 15px;">
                ${t('notifBody')}
            </div>
            <div style="display: flex; gap: 10px; justify-content: center;">
                <button id="allowNotifications" style="
                    background: white;
                    color: #667eea;
                    border: none;
                    padding: 10px 20px;
                    border-radius: 8px;
                    font-weight: 600;
                    cursor: pointer;
                    font-size: 14px;
                ">${t('notifAccept')}</button>
                <button id="denyNotifications" style="
                    background: rgba(255,255,255,0.2);
                    color: white;
                    border: none;
                    padding: 10px 20px;
                    border-radius: 8px;
                    font-weight: 600;
                    cursor: pointer;
                    font-size: 14px;
                ">${t('notifDeny')}</button>
            </div>
        </div>
    `;

    document.body.appendChild(prompt);

    // Handle allow button
    document.getElementById('allowNotifications').addEventListener('click', async () => {
        localStorage.setItem('push_notification_asked', 'true');
        prompt.remove();

        const permission = await Notification.requestPermission();
        if (permission === 'granted') {
            await subscribeToPushNotifications();
        }
    });

    // Handle deny button
    document.getElementById('denyNotifications').addEventListener('click', () => {
        localStorage.setItem('push_notification_asked', 'true');
        prompt.remove();
    });
}

// Initialize map
function initMap() {
    // Initialize user ID on map load
    getUserId();
    
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
                }).addTo(map).bindPopup(t('yourLocation')).openPopup();
                
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

            // Add new markers and collect bounds
            const bounds = [];
            data.data.forEach(sighting => {
                addSightingMarker(sighting);
                bounds.push([parseFloat(sighting.latitude), parseFloat(sighting.longitude)]);
            });

            // Automatically fit map to show all markers
            if (bounds.length > 0) {
                if (bounds.length === 1) {
                    // Single marker - center on it
                    map.setView(bounds[0], 13);
                } else {
                    // Multiple markers - fit bounds to show all
                    map.fitBounds(bounds, { padding: [50, 50] });
                }
            }
        }
    } catch (error) {
        console.error('Error loading sightings:', error);
    }
}

// Add a marker for a sighting
function addSightingMarker(sighting) {
    const species = sighting.species || 'Unknown';
    
    // Add slight random offset to prevent markers from stacking at exact same location
    // This helps when multiple users report from the same area
    const offsetLat = (Math.random() - 0.5) * 0.0005; // ~50 meters offset
    const offsetLon = (Math.random() - 0.5) * 0.0005;
    const displayLat = parseFloat(sighting.latitude) + offsetLat;
    const displayLon = parseFloat(sighting.longitude) + offsetLon;
    
    // Create custom icon with species label
    const customIcon = L.divIcon({
        className: 'custom-marker',
        html: `
            <div style="position: relative;">
                <div style="
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white;
                    padding: 8px 12px;
                    border-radius: 8px;
                    font-weight: bold;
                    font-size: 13px;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
                    text-align: center;
                    border: 3px solid white;
                    position: relative;
                    max-width: 200px;
                    min-width: 100px;
                    word-wrap: break-word;
                    overflow-wrap: break-word;
                    hyphens: auto;
                    line-height: 1.3;
                ">
                    🧊 ${species}
                </div>
            </div>
        `,
        iconSize: [200, 60],
        iconAnchor: [100, 30]
    });
    
    const marker = L.marker([displayLat, displayLon], {
        icon: customIcon
    }).addTo(map);
    
    // Calculate time remaining
    let expiresText = '';
    if (sighting.expires_at) {
        const expiresAt = new Date(sighting.expires_at);
        const now = new Date();
        const msRemaining = expiresAt - now;
        const hoursRemaining = Math.floor(msRemaining / (1000 * 60 * 60));
        const minutesRemaining = Math.floor((msRemaining % (1000 * 60 * 60)) / (1000 * 60));
        
        if (msRemaining > 0) {
            expiresText = `<div class="popup-expires">${t('expiresInPopup')} ${hoursRemaining}h ${minutesRemaining}m</div>`;
        }
    }
    
    const createdAt = new Date(sighting.created_at);
    
    const popupContent = `
        <div style="text-align: center;">
            <div style="width: 200px; padding: 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px; margin-bottom: 10px;">
                <div style="font-size: 48px; margin-bottom: 10px;">🦌</div>
                <div style="font-size: 20px; font-weight: bold; color: white; text-shadow: 0 2px 4px rgba(0,0,0,0.2);">${species}</div>
            </div>
            <div class="popup-info" style="text-align: left;">
                <div class="popup-time">${createdAt.toLocaleString()}</div>
                <div>📍 ${parseFloat(sighting.latitude).toFixed(6)}, ${parseFloat(sighting.longitude).toFixed(6)}</div>
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
    
    details.innerHTML = `${t('expiresIn')} ${minutesRemaining} ${t('minutes')}`;
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
async function uploadSighting(species, location) {
    const formData = new FormData();
    formData.append('species', species);
    formData.append('lat', location.lat);
    formData.append('lon', location.lon);
    formData.append('user_id', getUserId()); // Add user identification

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
            successEl.textContent = t('uploadSuccess');
            setTimeout(() => {
                closeUploadModal();
                loadSightings();
            }, 1500);
        } else {
            errorEl.textContent = data.error || t('uploadFailed');
            submitBtn.disabled = false;
        }
    } catch (error) {
        console.error('Upload error:', error);
        errorEl.textContent = t('networkError');
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
    document.getElementById('speciesInput').value = '';
    document.getElementById('uploadError').textContent = '';
    document.getElementById('uploadSuccess').textContent = '';
    document.getElementById('locationInfo').style.display = 'none';
    document.getElementById('submitUpload').disabled = true;
    currentLocation = null;
}

function closeConfirmationModal() {
    document.getElementById('confirmationModal').style.display = 'none';
    currentConfirmationSighting = null;
}

// Event listeners
document.addEventListener('DOMContentLoaded', () => {
    setLanguage(currentLang); // Apply saved language preference
    initMap();
    loadSightings();
    trackVisit(); // Track visitor analytics

    // Show notification prompt after a short delay (3 seconds)
    setTimeout(showNotificationPrompt, 3000);

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

    // Species input validation
    function validateForm() {
        const species = document.getElementById('speciesInput').value.trim();
        const submitBtn = document.getElementById('submitUpload');
        
        // Enable submit if we have species and current location
        submitBtn.disabled = !(species && currentLocation);
    }
    
    document.getElementById('speciesInput').addEventListener('input', validateForm);

    // Use current location button
    document.getElementById('useLocationBtn').addEventListener('click', () => {
        const locationInfo = document.getElementById('locationInfo');
        const errorEl = document.getElementById('uploadError');
        
        if (navigator.geolocation) {
            locationInfo.textContent = t('gettingLocation');
            locationInfo.style.display = 'block';
            locationInfo.style.color = '#666';
            errorEl.textContent = '';
            
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    currentLocation = {
                        lat: position.coords.latitude,
                        lon: position.coords.longitude
                    };
                    locationInfo.textContent = `${t('locationAcquired')} ${currentLocation.lat.toFixed(6)}, ${currentLocation.lon.toFixed(6)}`;
                    locationInfo.style.color = '#28a745';
                    validateForm();
                },
                (error) => {
                    locationInfo.style.display = 'none';
                    errorEl.textContent = t('locationError');
                    currentLocation = null;
                    validateForm();
                },
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0
                }
            );
        } else {
            errorEl.textContent = t('geoNotSupported');
        }
    });

    // Submit sighting
    document.getElementById('submitUpload').addEventListener('click', () => {
        const speciesInput = document.getElementById('speciesInput');
        const species = speciesInput.value.trim();
        
        if (species && currentLocation) {
            uploadSighting(species, currentLocation);
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
