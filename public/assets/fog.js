// Inject shared fog markup and add page class to ensure content sits above fog
(function(){
  if (document.getElementById('sharedFog')) return;
  const fog = document.createElement('div');
  fog.id = 'sharedFog';
  fog.className = 'bg-fog-shared';
  fog.innerHTML = '<div class="fog-blob-shared b1"></div><div class="fog-blob-shared b2"></div><div class="fog-blob-shared b3"></div>';
  document.documentElement.appendChild(fog);
  // add helper class to main containers
  const mains = document.querySelectorAll('main, .container, .login-wrap, .card, .login-card, .content');
  mains.forEach(m => m.classList.add('page-above-fog'));
})();
