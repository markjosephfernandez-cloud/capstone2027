require('dotenv').config();  // Load environment variables from .env file
const express = require('express');
const mysql = require('mysql2');
const cors = require('cors');
const app = express();
const port = 3002;  // Changed the port number to 3002

app.use(cors({
  origin: 'http://localhost:3000', 
  methods: ['GET', 'POST', 'OPTIONS'],
  allowedHeaders: ['Content-Type', 'Authorization'],
  credentials: true
}));

app.use(express.json());

// MySQL connection setup
const db = mysql.createConnection({
  host: 'localhost',
  user: 'root',
  password: 'guest1234',
  database: 'fumedia',
});

db.connect((err) => {
  if (err) {
    console.error('Error connecting to the database:', err.stack);
    return;
  }
  console.log('Connected to the database');
});

// Login route
app.post('/api/login', (req, res) => {
  const { UniqueID, password } = req.body;
  
  // Query to select the user by UniqueID and compare the password hash
  const query = 'SELECT * FROM user WHERE UniqueID = ?';

  db.query(query, [UniqueID], (err, results) => {
    if (err) {
      console.error('Database query error:', err.stack);
      return res.status(500).json({ error: 'Database query error' });
    }

    if (results.length > 0) {
      const user = results[0];

      // Compare the plain password with the stored hashed password
      if (password === user.PasswordHash) {
        return res.json({ success: true, message: 'Login successful' });
      } else {
        return res.status(401).json({ error: 'Invalid credentials' });
      }
    } else {
      return res.status(401).json({ error: 'Invalid credentials' });
    }
  }); 
});

app.listen(port, () => {
  console.log(`Express server running on http://localhost:${port}`);
});