// checkin.js
class CheckInInterface {
  constructor(eventId) {
    this.eventId = eventId;
    this.searchInput = document.getElementById('member-search');
    this.resultsContainer = document.getElementById('search-results');
    this.debounceTimer = null;
    
    this.init();
  }
  
  init() {
    if (!this.searchInput || !this.resultsContainer) {
      console.error('Check-in interface elements not found');
      return;
    }
    
    // Debounce search input
    this.searchInput.addEventListener('input', (e) => {
      clearTimeout(this.debounceTimer);
      const query = e.target.value.trim();
      
      if (query.length >= 2) {
        this.debounceTimer = setTimeout(() => {
          this.searchMembers(query);
        }, 300);
      } else {
        this.resultsContainer.innerHTML = '';
      }
    });
    
    // Focus search box on load
    this.searchInput.focus();
    
    // Allow Enter key to check in first result
    this.searchInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && this.resultsContainer.children.length > 0) {
        const firstCard = this.resultsContainer.querySelector('.member-card:not(.checked-in)');
        if (firstCard) {
          const userId = firstCard.dataset.userId;
          if (userId) {
            this.checkIn(parseInt(userId));
          }
        }
      }
    });
  }
  
  async searchMembers(query) {
    try {
      LoadingIndicator.showSpinner(this.resultsContainer);
      
      const response = await fetch(`/api/members/search?q=${encodeURIComponent(query)}&event_id=${this.eventId}`);
      const data = await response.json();
      
      LoadingIndicator.hideSpinner(this.resultsContainer);
      
      if (data.success) {
        this.displayResults(data.data);
      } else {
        this.showError(data.message || 'Search failed');
        this.resultsContainer.innerHTML = '';
      }
    } catch (error) {
      console.error('Search error:', error);
      LoadingIndicator.hideSpinner(this.resultsContainer);
      this.showError('Search failed. Please try again.');
      this.resultsContainer.innerHTML = '';
    }
  }
  
  displayResults(members) {
    if (members.length === 0) {
      this.resultsContainer.innerHTML = '<div class="no-results">No members found. Try a different search term.</div>';
      return;
    }
    
    const html = members.map(member => this.renderMemberCard(member)).join('');
    this.resultsContainer.innerHTML = html;
    
    // Attach event listeners
    this.attachEventListeners();
  }
  
  renderMemberCard(member) {
    const checkedInClass = member.checked_in ? 'checked-in' : '';
    const checkedInBadge = member.checked_in 
      ? `<span class="badge-success">✓ CHECKED IN (${member.checked_in_at || 'Just now'})</span>`
      : '';
    
    const rsvpInfo = member.rsvp_status ? `<div>RSVP: ${member.rsvp_status}</div>` : '';
    const paymentInfo = member.payment_amount ? `<div>Paid: $${member.payment_amount}</div>` : '';
    
    return `
      <div class="member-card ${checkedInClass}" data-user-id="${member.id}">
        <div class="member-info">
          <h3>${this.escapeHtml(member.first_name)} ${this.escapeHtml(member.last_name)}</h3>
          ${checkedInBadge}
          <div class="member-details">
            ${member.email ? `<span>${this.escapeHtml(member.email)}</span>` : ''}
            ${member.phone ? `<span>${this.escapeHtml(member.phone)}</span>` : ''}
          </div>
          ${rsvpInfo}
          ${paymentInfo}
        </div>
        <div class="member-actions">
          ${member.checked_in 
            ? `<button class="btn-secondary" onclick="checkInInterface.undoCheckIn(${member.id})" aria-label="Undo check-in for ${this.escapeHtml(member.first_name)} ${this.escapeHtml(member.last_name)}">Undo Check-In</button>`
            : `<button class="btn-primary btn-large" onclick="checkInInterface.checkIn(${member.id})" aria-label="Check in ${this.escapeHtml(member.first_name)} ${this.escapeHtml(member.last_name)}">✓ CHECK IN NOW</button>`
          }
        </div>
      </div>
    `;
  }
  
  escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }
  
  attachEventListeners() {
    // Event listeners are attached via onclick in the HTML for simplicity
    // This could be refactored to use event delegation if needed
  }
  
  async checkIn(userId) {
    const button = event?.target || document.querySelector(`[onclick*="checkIn(${userId})"]`);
    if (button) {
      LoadingIndicator.show(button);
    }
    
    try {
      const response = await fetch('/api/attendance/checkin', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          event_id: this.eventId,
          user_id: userId
        })
      });
      
      const data = await response.json();
      
      if (data.success) {
        Toast.success('Member checked in successfully!');
        this.updateCheckInCount();
        // Refresh search results
        const query = this.searchInput.value.trim();
        if (query.length >= 2) {
          this.searchMembers(query);
        }
      } else {
        Toast.error(data.message || 'Check-in failed');
      }
    } catch (error) {
      console.error('Check-in error:', error);
      Toast.error('Check-in failed. Please try again.');
    } finally {
      if (button) {
        LoadingIndicator.hide(button);
      }
    }
  }
  
  async undoCheckIn(userId) {
    const confirmed = await confirmAction({
      title: 'Undo Check-In',
      message: 'Are you sure you want to undo the check-in for this member?',
      type: 'warning',
      okText: 'Undo',
      cancelText: 'Cancel'
    });
    
    if (!confirmed) {
      return;
    }
    
    const button = event?.target || document.querySelector(`[onclick*="undoCheckIn(${userId})"]`);
    if (button) {
      LoadingIndicator.show(button);
    }
    
    try {
      const response = await fetch('/api/attendance/undo', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          event_id: this.eventId,
          user_id: userId
        })
      });
      
      const data = await response.json();
      
      if (data.success) {
        Toast.success('Check-in undone');
        this.updateCheckInCount();
        // Refresh search results
        const query = this.searchInput.value.trim();
        if (query.length >= 2) {
          this.searchMembers(query);
        }
      } else {
        Toast.error(data.message || 'Failed to undo check-in');
      }
    } catch (error) {
      console.error('Undo error:', error);
      Toast.error('Failed to undo check-in.');
    } finally {
      if (button) {
        LoadingIndicator.hide(button);
      }
    }
  }
  
  updateCheckInCount() {
    // Fetch updated count and update display
    fetch(`/api/attendance/${this.eventId}/count`)
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          const statsElement = document.querySelector('.checkin-stats');
          if (statsElement) {
            statsElement.textContent = 
              `✓ Checked In: ${data.data.checked_in} / ${data.data.expected} (${data.data.percentage}%)`;
          }
        }
      })
      .catch(error => {
        console.error('Failed to update check-in count:', error);
      });
  }
  
  showError(message) {
    Toast.error(message);
  }
}

// Initialize on page load
let checkInInterface;
document.addEventListener('DOMContentLoaded', () => {
  const eventIdElement = document.querySelector('[data-event-id]');
  const eventId = eventIdElement ? eventIdElement.dataset.eventId : null;
  
  if (eventId) {
    checkInInterface = new CheckInInterface(eventId);
  }
});
