# Vacation Brain landing-guide media

Replace the placeholder WebP files in this folder with the approved generated artwork using the same filenames:

- hero-destination-guide.webp
- guide-destinations.webp
- guide-trips.webp
- guide-activities.webp
- guide-travel-guides.webp
- getaway-multiday.webp
- getaway-weekend.webp
- getaway-daytrip.webp
- phone-travel-companion.webp
- footer-travel-guide.webp

`index.php` checks each asset with `local_media_exists()` before rendering image-backed sections. Keeping the names stable means production artwork can be dropped in without changing PHP or CSS.
