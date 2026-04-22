// C:\xampp\htdocs\metro-north-board\frontend\src\App.jsx
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import Board from './pages/Board';
import Embed from './pages/Embed';

export default function App() {
  return (
    <BrowserRouter>
      <Routes>-
        <Route path="/" element={<Navigate to="/board" replace />} />
        <Route path="/board" element={<Board />} />
        <Route path="/embed" element={<Embed />} />
      </Routes>
    </BrowserRouter>
  );
}
