// api.js
class APIClient {
  constructor(baseURL = '/api') {
    this.baseURL = baseURL;
  }
  
  async request(endpoint, options = {}) {
    // Ensure endpoint starts with /
    if (!endpoint.startsWith('/')) {
      endpoint = '/' + endpoint;
    }
    // Ensure baseURL doesn't end with /
    const base = this.baseURL.endsWith('/') ? this.baseURL.slice(0, -1) : this.baseURL;
    const url = `${base}${endpoint}`;
    console.log('API Request:', { 
      'this.baseURL': this.baseURL, 
      'endpoint': endpoint, 
      'constructed url': url,
      'full url will be': window.location.origin + url
    });
    const config = {
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...options.headers
      },
      credentials: 'same-origin',
      ...options
    };
    
    if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
      config.body = JSON.stringify(options.body);
    } else if (options.body) {
      config.body = options.body;
      // Remove Content-Type header for FormData to let browser set it with boundary
      if (options.body instanceof FormData) {
        delete config.headers['Content-Type'];
      }
    }
    
    try {
      const response = await fetch(url, config);
      const data = await response.json();
      
      if (!response.ok) {
        throw new Error(data.message || 'Request failed');
      }
      
      return data;
    } catch (error) {
      console.error('API Error:', error);
      throw error;
    }
  }
  
  async get(endpoint) {
    return this.request(endpoint, { method: 'GET' });
  }
  
  async post(endpoint, data) {
    return this.request(endpoint, { method: 'POST', body: data });
  }
  
  async put(endpoint, data) {
    return this.request(endpoint, { method: 'PUT', body: data });
  }
  
  async delete(endpoint) {
    return this.request(endpoint, { method: 'DELETE' });
  }
}
