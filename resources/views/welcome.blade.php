<!DOCTYPE html><html class="dark" lang="en"><head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<link href="https://fonts.googleapis.com/css2?family=Ephesis&family=Hanken+Grotesk:wght@300;400;600&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=block" rel="stylesheet">
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<script id="tailwind-config">
        try {
            tailwind.config = {
                darkMode: "class",
                theme: {
                    extend: {
                        "colors": {
                            "surface-bright": "#393939",
                            "error": "#ffb4ab",
                            "on-tertiary-fixed": "#1a1c1b",
                            "error-container": "#93000a",
                            "surface-dim": "#131313",
                            "on-tertiary-fixed-variant": "#454746",
                            "secondary": "#e9c349",
                            "on-tertiary": "#2f3130",
                            "surface-container-highest": "#353535",
                            "surface-container": "#1f2020",
                            "on-tertiary-container": "#7c7e7c",
                            "on-primary": "#313030",
                            "on-secondary-fixed-variant": "#574500",
                            "background": "#131313",
                            "inverse-surface": "#e4e2e1",
                            "inverse-primary": "#5f5e5e",
                            "tertiary-container": "#111312",
                            "surface-container-lowest": "#0e0e0e",
                            "on-primary-fixed": "#1c1b1b",
                            "on-error": "#690005",
                            "primary": "#c8c6c5",
                            "tertiary-fixed-dim": "#c6c7c5",
                            "on-primary-fixed-variant": "#474646",
                            "inverse-on-surface": "#303030",
                            "outline-variant": "#444748",
                            "surface-variant": "#353535",
                            "secondary-container": "#af8d11",
                            "on-background": "#e4e2e1",
                            "primary-container": "#121212",
                            "secondary-fixed": "#ffe088",
                            "outline": "#8e9192",
                            "on-secondary-fixed": "#241a00",
                            "on-secondary-container": "#342800",
                            "surface-container-low": "#1b1c1c",
                            "surface-tint": "#c8c6c5",
                            "primary-fixed-dim": "#c8c6c5",
                            "on-primary-container": "#7e7d7d",
                            "primary-fixed": "#e5e2e1",
                            "tertiary": "#c6c7c5",
                            "on-surface": "#e4e2e1",
                            "on-error-container": "#ffdad6",
                            "on-secondary": "#3c2f00",
                            "tertiary-fixed": "#e2e3e1",
                            "surface-container-high": "#2a2a2a",
                            "surface": "#131313",
                            "secondary-fixed-dim": "#e9c349",
                            "on-surface-variant": "#c4c7c7"
                        },
                        "borderRadius": {
                            "DEFAULT": "0.125rem",
                            "lg": "0.25rem",
                            "xl": "0.5rem",
                            "full": "0.75rem"
                        },
                        "spacing": {
                            "unit": "8px",
                            "gutter": "24px",
                            "stack-sm": "16px",
                            "section-padding": "120px",
                            "stack-lg": "64px",
                            "container-max": "1200px",
                            "stack-md": "32px"
                        },
                        "fontFamily": {
                            "label-caps": ["Hanken Grotesk"],
                            "body-md": ["Hanken Grotesk"],
                            "display-lg-mobile": ["Hanken Grotesk"],
                            "headline-md": ["Hanken Grotesk"],
                            "display-lg": ["Hanken Grotesk"],
                            "body-lg": ["Hanken Grotesk"]
                        },
                        "fontSize": {
                            "label-caps": ["12px", {"lineHeight": "1.0", "letterSpacing": "0.2em", "fontWeight": "600"}],
                            "body-md": ["16px", {"lineHeight": "1.6", "fontWeight": "400"}],
                            "display-lg-mobile": ["40px", {"lineHeight": "1.2", "letterSpacing": "-0.01em", "fontWeight": "600"}],
                            "headline-md": ["32px", {"lineHeight": "1.3", "fontWeight": "600"}],
                            "display-lg": ["64px", {"lineHeight": "1.1", "letterSpacing": "-0.02em", "fontWeight": "600"}],
                            "body-lg": ["18px", {"lineHeight": "1.6", "letterSpacing": "0.01em", "fontWeight": "300"}]
                        }
                    },
                },
            }
        } catch (_e) {}
    </script>
<style>
        @keyframes float {
            0% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-30px) rotate(2deg); }
            100% { transform: translateY(0px) rotate(0deg); }
        }
        .animate-float {
            animation: float 6s ease-in-out infinite;
        }
        .mesh-gradient {
            background-color: #131313;
            background-image:
                radial-gradient(at 0% 0%, rgba(59, 130, 246, 0.4) 0px, transparent 50%),
                radial-gradient(at 50% 0%, rgba(139, 92, 246, 0.4) 0px, transparent 50%),
                radial-gradient(at 100% 0%, rgba(236, 72, 153, 0.4) 0px, transparent 50%),
                radial-gradient(at 50% 50%, rgba(59, 130, 246, 0.2) 0px, transparent 50%);
            background-attachment: fixed;
        }
        .glass {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .text-glow {
            text-shadow: 0 0 30px rgba(255, 255, 255, 0.3);
        }
    </style>
</head>
<body class="bg-background text-on-surface font-body-md overflow-x-hidden selection:bg-secondary selection:text-on-secondary mesh-gradient">
<!-- Background Elements -->

<!-- Top Navigation Bar -->
<!-- Main Content Canvas -->
<main class="relative min-h-screen flex flex-col items-center justify-center px-gutter pt-24 pb-stack-lg z-10">
<div class="max-w-4xl w-full text-center space-y-stack-md">
    <h1 class="font-display-lg-mobile md:font-display-lg text-display-lg-mobile md:text-display-lg text-on-surface leading-[1.1] text-glow"
        style="font-family: 'Ephesis', cursive; font-weight: 400;">
        Coming Soon
    </h1>
</div>
<div class="absolute bottom-8 left-0 w-full text-center"><span class="font-body-md text-on-surface opacity-70 tracking-[0.3em] text-[12px]">July 2026</span></div></main>
<!-- Footer Shell -->
<script>
    // Smooth fade-in animation for the hero content
    document.addEventListener('DOMContentLoaded', () => {
        const elements = document.querySelectorAll('.animate-fade-in-up');
        elements.forEach((el, index) => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(20px)';
            el.style.transition = 'all 1.4s cubic-bezier(0.22, 1, 0.36, 1)';

            setTimeout(() => {
                el.style.opacity = '1';
                el.style.transform = 'translateY(0)';
            }, 300 * (index + 1));
        });
    });
</script>
</body>
</html>