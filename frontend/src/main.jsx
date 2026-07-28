// Vite entry point: mounts <App/> into the #root element (see index.html) with
// React 18's createRoot. No app logic lives here.

import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.jsx'

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
