// src/js/app.js

// Adjust the path to point from your JS file to your images folder
import.meta.glob('../images/**/*', { eager: true });

// 1. Import your CSS
import '../css/main.css';

// 2. TELL VITE TO INCLUDE IMAGES
// This tells Vite: "Look in the images folder and include everything"
import.meta.glob([
    '../images/**/*.{png,jpg,jpeg,svg,webp,gif}',
]);

// 3. Your other JS (Alpine, GSAP, etc.)
console.log("Assets initialized");