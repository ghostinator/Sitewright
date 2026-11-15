const { defineConfig } = require('@playwright/test');
module.exports = defineConfig({
  testDir: '.', testMatch: '*.spec.js', workers: 1, retries: 0, timeout: 60000, reporter: 'list',
  use: { baseURL: process.env.BASE || 'http://localhost:8081', actionTimeout: 10000, trace: 'off' },
});
