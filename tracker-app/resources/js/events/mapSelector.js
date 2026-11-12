//  zoom for the browser's position, and for the rougher guess from the IP address; both wide
//  enough to show the events around the user
const USER_ZOOM = 9;
const IP_ZOOM = 8;

export default function mapSelector(events, apiKey) {
    return {
        map: null,
        markers: [],
        located: false,
        popup: null,

        init() {
            this.map = new google.maps.Map(this.$el);

            this.showEvents();

            //  fitBounds applies on the first idle, so wait for it: a quick answer (a cached
            //  position, or the IP guess) would otherwise be overwritten by the events view
            google.maps.event.addListenerOnce(this.map, 'idle', () => {
                this.guessFromIp();
                this.centerOnUser();
            });

            events.forEach(event => {
                const marker = new google.maps.Marker({
                    position: { lat: event.lat, lng: event.lng },
                    map: this.map,
                    title: event.name,
                });

                marker.addListener('click', () => {
                    this.showBootstrapPopup(event, marker);
                });

                this.markers.push(marker);
            });
        },

        //  one popup for the whole map: clicking another pin moves it there, so only one
        //  event is open at a time
        showBootstrapPopup(event, marker) {
            if (!this.popup) {
                this.popup = new google.maps.InfoWindow();

                //  a click on the map itself closes it
                this.map.addListener('click', () => this.popup.close());
            }

            this.popup.setContent(this.popupContent(event));
            this.popup.open({ map: this.map, anchor: marker });
        },

        //  built from elements so the event's name is shown as text, never read as HTML
        popupContent(event) {
            const card = document.createElement('div');
            card.className = 'card shadow-sm';
            card.style.width = '16rem';
            card.innerHTML = `
                <div class="card-body p-2">
                    <h6 class="card-title mb-1">
                        <a class="text-decoration-none fw-bold"></a>
                    </h6>
                    <div class="small text-muted"></div>
                </div>
            `;

            const link = card.querySelector('a');
            link.href = event.url;
            link.textContent = event.name;
            card.querySelector('.text-muted').textContent = event.date_range;

            return card;
        },

        //  The first view: the upcoming events, or the continental US when there are none
        showEvents() {
            if (events.length === 0) {
                this.map.setCenter({ lat: 39.8, lng: -98.6 });
                this.map.setZoom(4);
            } else if (events.length === 1) {
                this.map.setCenter({ lat: events[0].lat, lng: events[0].lng });
                this.map.setZoom(10);
            } else {
                const bounds = new google.maps.LatLngBounds();
                events.forEach(event => bounds.extend({ lat: event.lat, lng: event.lng }));
                this.map.fitBounds(bounds);
            }
        },

        //  Google's Geolocation API with the Maps key: no permission prompt, so it shows the
        //  user's area while the browser asks for their position. If the API isn't enabled
        //  for the key, the map stays on the events.
        guessFromIp() {
            if (!apiKey) {
                return;
            }

            fetch(`https://www.googleapis.com/geolocation/v1/geolocate?key=${encodeURIComponent(apiKey)}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ considerIp: true }),
            })
                .then(response => response.ok ? response.json() : Promise.reject(response.status))
                .then(({ location }) => {
                    //  the browser's position is better; don't replace it
                    if (!this.located) {
                        this.map.setCenter(location);
                        this.map.setZoom(IP_ZOOM);
                    }
                })
                .catch(err => console.warn("No location from the IP address", err));
        },

        centerOnUser() {
            if (!navigator.geolocation) {
                console.warn("Geolocation not supported");
                return;
            }

            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    const { latitude, longitude } = pos.coords;

                    this.located = true;
                    this.map.setCenter({ lat: latitude, lng: longitude });
                    this.map.setZoom(USER_ZOOM);

                    // Optional: drop a marker for the user
                    new google.maps.Marker({
                        position: { lat: latitude, lng: longitude },
                        map: this.map,
                        title: "You are here",
                        icon: {
                            path: google.maps.SymbolPath.CIRCLE,
                            scale: 6,
                            fillColor: "#0d6efd",
                            fillOpacity: 1,
                            strokeColor: "#fff",
                            strokeWeight: 2,
                        },
                    });
                },
                (err) => {
                    //  denied, unavailable or timed out: the map stays on the IP guess or the events
                    console.warn("Geolocation unavailable", err);
                },
                //  a fix from the last 10 minutes is close enough and comes back at once
                { maximumAge: 10 * 60 * 1000, timeout: 10 * 1000 }
            );
        }

    }
}
