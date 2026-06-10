<?php
/**
 * Shared <head> block.
 * Requires $pageTitle (string) to be set before including.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= h($pageTitle ?? 'Credit Health Management System') ?></title>
  <script src="https://cdn.tailwindcss.com/3.4.16"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/4.6.0/remixicon.min.css" />
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { sans: ['Inter', 'sans-serif'] },
          colors: { primary: "#7c3aed", secondary: "#3b82f6" },
          borderRadius: {
            none:"0px", sm:"4px", DEFAULT:"8px", md:"12px",
            lg:"16px", xl:"20px", "2xl":"24px", "3xl":"32px",
            full:"9999px", button:"8px"
          }
        }
      }
    };
  </script>
  <style>
    body { font-family: 'Inter', sans-serif; }
    :where([class^="ri-"])::before { content: "\f3c2"; }
    /* Thin scrollbar for sidebar */
    .sidebar-scroll::-webkit-scrollbar { width: 4px; }
    .sidebar-scroll::-webkit-scrollbar-thumb { background: #4b5563; border-radius: 2px; }
    /* Card hover lift */
    .card-hover { transition: transform 0.15s ease, box-shadow 0.15s ease; }
    .card-hover:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
    /* Fade-in animation */
    @keyframes fadeIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:none; } }
    .fade-in { animation: fadeIn 0.3s ease forwards; }
    /* Progress bar animation */
    @keyframes growWidth { from { width: 0%; } to { width: var(--target-width); } }
    .progress-animate { animation: growWidth 0.8s ease forwards; }
  </style>
</head>
<body class="bg-gray-50 min-h-screen" style="font-family:'Inter',sans-serif">
