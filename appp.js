// backend/app.js ou server.js
const cors = require('cors');

// Configuration CORS complète et CORRECTE
const corsOptions = {
  origin: [
    'https://stock-management-frontend-liard.vercel.app',
    'https://stock-management-frontend.vercel.app',
    'http://localhost:3000',
    'http://localhost:3001',
    'http://localhost:5000'
  ],
  credentials: true,
  optionsSuccessStatus: 200,
  methods: ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH'],
  allowedHeaders: [
    'Content-Type',
    'Authorization',
    'x-auth-token',      // ⚠️ TRÈS IMPORTANT - Ajoutez ceci
    'X-Requested-With',
    'Accept',
    'Origin'
  ],
  exposedHeaders: ['x-auth-token']  // Expose le token si besoin
};

// Appliquer CORS
app.use(cors(corsOptions));

// Pour les requêtes OPTIONS (pré-vol)
app.options('*', cors(corsOptions));