<?php
session_start();
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GigPay - Automated Payroll with Biometric Integration</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Google Fonts: Montserrat & Inter for a premium look -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;800;900&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        heading: ['Montserrat', 'sans-serif'],
                    },
                    colors: {
                        primary: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            400: '#60a5fa',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                            800: '#1e40af',
                            900: '#1e3a8a',
                            950: '#172554',
                        }
                    }
                }
            }
        }
    </script>
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .hero-heading {
            font-family: 'Montserrat', sans-serif;
            text-shadow: 0 4px 12px rgba(0, 0, 0, 0.85), 0 2px 4px rgba(0, 0, 0, 0.6);
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-900 font-sans antialiased selection:bg-primary-500 selection:text-white">

    <!-- Navbar (Slimmer Header) -->
    <header class="sticky top-0 z-50 bg-white/85 backdrop-blur-md border-b border-slate-200 shadow-xs">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-20 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <div class="flex items-center space-x-2">
                    <img src="pay.png" alt="Pay Logo" class="h-9 w-auto object-contain">
                    <div class="bg-primary-600 text-white p-2.5 rounded-xl shadow-sm flex items-center justify-center">
                        <i class="fa-solid fa-fingerprint text-lg"></i>
                    </div>
                </div>
                <span class="text-2xl font-black tracking-tight text-slate-900">Gig<span class="text-primary-600">Pay</span></span>
            </div>

            <div class="flex items-center space-x-4">
                <a href="login.php" class="text-sm font-semibold text-slate-700 hover:text-primary-600 px-3 py-2 transition">Sign In</a>
                <a href="login.php" class="bg-primary-600 hover:bg-primary-700 text-white text-sm font-semibold px-5 py-2.5 rounded-xl shadow-sm transition-all duration-200 transform hover:-translate-y-0.5">Login Portal</a>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="relative overflow-hidden py-36 sm:py-48 bg-cover bg-center border-b border-slate-200" style="background-image: url('ff.png');">
        <!-- Balanced dark overlay to make text pop clearly without outlines -->
        <div class="absolute inset-0 bg-slate-950/65 backdrop-blur-[0.5px]"></div>

        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10 flex flex-col items-center justify-center text-center">
            
            <!-- Headline forced into 3 distinct lines using 'block' -->
            <h1 class="text-3xl sm:text-5xl lg:text-6xl font-black tracking-tight leading-tight mb-8 hero-heading">
                <span class="text-red-500 block">Your Thumbprint</span> 
                <span class="text-emerald-400 block">Is Your Timesheet</span> 
                <span class="text-white block">And Your Wallet</span>
            </h1>
            
            <div class="flex justify-center">
                <a href="login.php" class="bg-primary-600 hover:bg-primary-700 text-white font-bold px-9 py-4 rounded-xl shadow-2xl hover:shadow-primary-600/40 text-base transition-all duration-200 transform hover:-translate-y-0.5 flex items-center space-x-3 border border-primary-500">
                    <span>Login to Portal</span>
                    <i class="fa-solid fa-arrow-right text-sm"></i>
                </a>
            </div>

        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-slate-950 text-slate-400 py-10 sm:py-12 border-t border-slate-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between text-center sm:text-left space-y-4 sm:space-y-0">
            <div class="flex items-center space-x-3">
                <div class="bg-primary-600 text-white p-2 rounded-lg">
                    <i class="fa-solid fa-fingerprint"></i>
                </div>
                <span class="text-xl font-bold text-white tracking-tight">GigPay</span>
            </div>
            <p class="text-sm font-medium">&copy; 2026 GigPay Inc. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>