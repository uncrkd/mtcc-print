// MTCC Form Additions - Supplementary Script
// This file adds extra functionality without modifying the main script.js

(function() {
  'use strict';
  
  // Wait for DOM and main script to load
  document.addEventListener('DOMContentLoaded', function() {
    setTimeout(initAdditions, 100);
  });
  
  function initAdditions() {
    console.log('Script additions loading...');
    
    // 1. Update pricing dimensions display
    setupPricingDimensions();
    
    // 2. Fix date/time dropdown overlap
    fixDateTimeOverlap();
    
    // 3. Setup alternative size selection with custom size highlight
    setupAlternativeSizeSelection();
    
    // 4. Watch for pricing updates to add current-tier class
    setupCurrentTierHighlight();
    
    console.log('Script additions loaded successfully');
  }
  
  // === PRICING DIMENSIONS ===
  function setupPricingDimensions() {
    var pricingSection = document.getElementById('pricingSection');
    if (!pricingSection) return;
    
    var observer = new MutationObserver(function() {
      updatePricingDimensions();
    });
    
    observer.observe(pricingSection, { 
      attributes: true, 
      attributeFilter: ['style'] 
    });
    
    var widthInput = document.getElementById('w');
    var heightInput = document.getElementById('h');
    
    if (widthInput) {
      widthInput.addEventListener('input', updatePricingDimensions);
    }
    if (heightInput) {
      heightInput.addEventListener('input', updatePricingDimensions);
    }
    
    updatePricingDimensions();
  }
  
  function updatePricingDimensions() {
    var dimensionsEl = document.getElementById('pricingDimensions');
    if (!dimensionsEl) return;
    
    var widthInput = document.getElementById('w');
    var heightInput = document.getElementById('h');
    var width = widthInput ? widthInput.value : '';
    var height = heightInput ? heightInput.value : '';
    
    if (width && height) {
      dimensionsEl.textContent = 'Poster Size ' + width + '" x ' + height + '"';
      dimensionsEl.style.display = 'inline';
    } else {
      dimensionsEl.textContent = '';
      dimensionsEl.style.display = 'none';
    }
  }
  
  // === CURRENT TIER HIGHLIGHT ===
  function setupCurrentTierHighlight() {
    var pricingContainer = document.getElementById('pricing');
    if (!pricingContainer) return;
    
    var observer = new MutationObserver(function() {
      var desktopCountdown = document.getElementById('desktopCountdown');
      if (desktopCountdown) {
        var tierWrapper = desktopCountdown.closest('.tier-wrapper');
        if (tierWrapper && !tierWrapper.classList.contains('current-tier')) {
          document.querySelectorAll('.tier-wrapper.current-tier').forEach(function(el) {
            el.classList.remove('current-tier');
          });
          tierWrapper.classList.add('current-tier');
        }
      }
      
      var mobileCountdown = document.getElementById('mobileCountdown');
      if (mobileCountdown) {
        var mobileTier = mobileCountdown.closest('.mobile-tier');
        if (mobileTier && !mobileTier.classList.contains('current-tier')) {
          document.querySelectorAll('.mobile-tier.current-tier').forEach(function(el) {
            el.classList.remove('current-tier');
          });
          mobileTier.classList.add('current-tier');
        }
      }
    });
    
    observer.observe(pricingContainer, { 
      childList: true, 
      subtree: true 
    });
  }
  
  // === DATE/TIME OVERLAP FIX ===
  function fixDateTimeOverlap() {
    var timeWrapper = document.querySelector('.time-select-wrapper');
    if (timeWrapper) {
      timeWrapper.addEventListener('click', function(e) {
        e.stopPropagation();
      }, true);
      
      timeWrapper.addEventListener('mousedown', function(e) {
        e.stopPropagation();
      }, true);
    }
  }
  
  // === ALTERNATIVE SIZE SELECTION ===
  function setupAlternativeSizeSelection() {
    // Store original selectPopularSize
    var originalSelectPopularSize = window.selectPopularSize;
    
    if (originalSelectPopularSize) {
      window.selectPopularSize = function(width, height) {
        var customSizeSection = document.getElementById('customSizeSection');
        if (customSizeSection) {
          customSizeSection.classList.remove('active');
        }
        originalSelectPopularSize(width, height);
      };
    }
    
    // Create selectAlternativeSize function
    window.selectAlternativeSize = function(width, height) {
      console.log('selectAlternativeSize called:', width, height);
      
      document.querySelectorAll('.popular-size-button-card').forEach(function(button) {
        button.classList.remove('selected');
      });
      
      document.querySelectorAll('.alternative-size-button').forEach(function(button) {
        button.classList.remove('selected');
      });
      
      var customSizeSection = document.getElementById('customSizeSection');
      if (customSizeSection) {
        customSizeSection.classList.add('active');
      }
      
      var widthInput = document.getElementById('w');
      var heightInput = document.getElementById('h');
      if (widthInput) widthInput.value = width;
      if (heightInput) heightInput.value = height;
      
      if (widthInput) {
        widthInput.dispatchEvent(new Event('input', { bubbles: true }));
      }
    };
    
    // Watch for alternative size buttons and override their onclick
    var popularSizesContainer = document.getElementById('popularSizes');
    if (popularSizesContainer) {
      var observer = new MutationObserver(function() {
        document.querySelectorAll('.alternative-size-button').forEach(function(button) {
          if (!button.dataset.enhanced) {
            button.dataset.enhanced = 'true';
            // Parse onclick to get dimensions
            var onclickAttr = button.getAttribute('onclick');
            if (onclickAttr) {
              var match = onclickAttr.match(/selectPopularSize\((\d+),\s*(\d+)\)/);
              if (match) {
                var width = parseInt(match[1]);
                var height = parseInt(match[2]);
                button.onclick = function(e) {
                  e.preventDefault();
                  window.selectAlternativeSize(width, height);
                };
              }
            }
          }
        });
      });
      
      observer.observe(popularSizesContainer, { 
        childList: true, 
        subtree: true 
      });
    }
    
    // Highlight custom size when typing
    var widthInput = document.getElementById('w');
    var heightInput = document.getElementById('h');
    
    function highlightCustomSize() {
      var customSizeSection = document.getElementById('customSizeSection');
      var width = widthInput ? widthInput.value : '';
      var height = heightInput ? heightInput.value : '';
      
      if (customSizeSection && (width || height)) {
        customSizeSection.classList.add('active');
      }
    }
    
    if (widthInput) {
      widthInput.addEventListener('focus', highlightCustomSize);
      widthInput.addEventListener('input', highlightCustomSize);
    }
    if (heightInput) {
      heightInput.addEventListener('focus', highlightCustomSize);
      heightInput.addEventListener('input', highlightCustomSize);
    }
  }
  
})();
