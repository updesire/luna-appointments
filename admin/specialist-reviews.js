(function ($) {
  'use strict';

  function getConfig() {
    return window.lunaSpecialistReviewsAdmin || {};
  }

  function postAjax(data) {
    var config = getConfig();

    if (!config.ajaxUrl) {
      return Promise.reject(new Error('ajaxUrl missing'));
    }

    return fetch(config.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: new URLSearchParams(data).toString()
    }).then(function (response) {
      return response.json();
    });
  }

  function updateSummary(summary) {
    if (!summary) {
      return;
    }

    var ratingField = document.querySelector('input[name="luna_specialist_rating"]');
    var countField = document.querySelector('input[name="luna_specialist_review_count"]');

    if (ratingField && summary.rating != null) {
      ratingField.value = String(summary.rating);
    }

    if (countField && summary.reviewCount != null) {
      countField.value = String(summary.reviewCount);
    }
  }

  function updateRaw(raw) {
    var textarea = document.querySelector('textarea[name="luna_specialist_reviews"]');
    if (textarea && typeof raw === 'string') {
      textarea.value = raw;
    }
  }

  function showNotice(wrap, message, tone) {
    if (!wrap) {
      return;
    }

    var node = wrap.querySelector('.luna-review-notice');
    if (!node) {
      return;
    }

    node.textContent = String(message || '');
    node.style.display = node.textContent ? 'block' : 'none';
    node.style.border = '1px solid transparent';

    if (tone === 'error') {
      node.style.background = '#fff1f2';
      node.style.borderColor = '#fecdd3';
      node.style.color = '#9f1239';
      return;
    }

    node.style.background = '#ecfdf5';
    node.style.borderColor = '#bbf7d0';
    node.style.color = '#166534';
  }

  function syncCount(wrap) {
    if (!wrap) {
      return;
    }

    var root = wrap.parentElement;
    if (!root) {
      return;
    }

    var countNode = root.querySelector('.luna-review-count');
    if (!countNode) {
      return;
    }

    var rows = wrap.querySelectorAll('.luna-review-row');
    countNode.textContent = rows.length.toLocaleString('fa-IR') + ' نظر';
  }

  function ensureEmptyState(wrap) {
    if (!wrap) {
      return;
    }

    var rows = wrap.querySelectorAll('.luna-review-row');
    var empty = wrap.querySelector('.luna-review-empty');

    if (rows.length > 0) {
      if (empty) {
        empty.remove();
      }
      return;
    }

    if (!empty) {
      empty = document.createElement('div');
      empty.className = 'luna-review-empty';
      empty.style.padding = '14px';
      empty.style.border = '1px solid #e5e5e5';
      empty.style.borderRadius = '10px';
      empty.style.background = '#fff';
      empty.textContent = 'هنوز نظری ثبت نشده است.';
      wrap.appendChild(empty);
    }
  }

  function handleAction(button) {
    var row = button.closest('.luna-review-row');
    var wrap = button.closest('.luna-specialist-reviews-admin');
    var config = getConfig();

    if (!row || !wrap || !config.nonce) {
      return;
    }

    var specialistId = wrap.getAttribute('data-specialist-id');
    var reviewId = row.getAttribute('data-review-id');
    var command = button.getAttribute('data-command');

    if (!specialistId || !reviewId || !command) {
      return;
    }

    if (command === 'delete' && !window.confirm('این نظر حذف شود؟')) {
      return;
    }

    button.disabled = true;
    showNotice(wrap, '', 'success');

    postAjax({
      action: 'luna_admin_manage_specialist_review',
      nonce: config.nonce,
      specialistPostId: specialistId,
      reviewId: reviewId,
      command: command
    })
      .then(function (payload) {
        if (!payload || !payload.success) {
          throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Request failed');
        }

        updateSummary(payload.data && payload.data.summary ? payload.data.summary : null);
        updateRaw(payload.data && payload.data.raw ? payload.data.raw : '');
        showNotice(wrap, payload.data && payload.data.message ? payload.data.message : 'انجام شد.', 'success');

        if (command === 'delete') {
          row.remove();
          syncCount(wrap);
          ensureEmptyState(wrap);
          return;
        }

        row.classList.remove('is-pending');
        var statusNode = row.querySelector('.luna-review-status');
        if (statusNode) {
          statusNode.textContent = 'تایید شده';
          statusNode.style.background = '#e8fff2';
          statusNode.style.color = '#0a6b3a';
          statusNode.style.borderColor = '#b8f0cf';
        }

        var approveButton = row.querySelector('.luna-review-action[data-command="approve"]');
        if (approveButton) {
          approveButton.remove();
        }

        syncCount(wrap);
      })
      .catch(function (error) {
        showNotice(wrap, error && error.message ? error.message : 'خطا در انجام عملیات', 'error');
      })
      .finally(function () {
        button.disabled = false;
      });
  }

  $(function () {
    $(document).on('click', '.luna-review-action', function (event) {
      event.preventDefault();
      handleAction(event.currentTarget);
    });
  });
}(jQuery));
